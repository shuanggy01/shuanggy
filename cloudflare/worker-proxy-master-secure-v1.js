/**
 * proxy-master — Secure Video Proxy V1
 *
 * Keeps the old Base64 proxy behavior for existing traffic.
 * Adds:
 *   https://v.asupanlendir.sbs/p/TOKEN
 *
 * Required Worker Secret:
 *   AL_PROXY_SECRET
 */

export default {
  async fetch(request, env) {
    return handleRequest(request, env);
  }
};

async function handleRequest(request, env) {
  const url = new URL(request.url);

  if (
    url.hostname === 'v.asupanlendir.sbs'
    && url.pathname.startsWith('/p/')
  ) {
    return handleSecureAsupanProxy(
      request,
      env
    );
  }

  return handleLegacyProxy(request);
}


/* ============================================================
 * SECURE ASUPANLENDIR PROXY
 * ============================================================
 */

async function handleSecureAsupanProxy(
  request,
  env
) {
  const requestUrl =
    new URL(request.url);

  const method =
    request.method.toUpperCase();

  if (method === 'OPTIONS') {
    return corsPreflight(request);
  }

  if (
    method !== 'GET'
    && method !== 'HEAD'
  ) {
    return plain(
      'Method Tidak Diizinkan.',
      405
    );
  }

  if (
    !env.AL_PROXY_SECRET
    || String(env.AL_PROXY_SECRET).length < 32
  ) {
    return plain(
      'Proxy belum dikonfigurasi.',
      503
    );
  }

  const token =
    requestUrl.pathname.slice(3);

  if (!token) {
    return plain(
      'Token video tidak ditemukan.',
      400
    );
  }

  let payload;

  try {
    payload = await decryptToken(
      token,
      String(env.AL_PROXY_SECRET)
    );
  } catch (error) {
    return plain(
      'Token video tidak valid.',
      403
    );
  }

  const now =
    Math.floor(Date.now() / 1000);

  if (
    !payload
    || payload.v !== 1
    || typeof payload.u !== 'string'
    || typeof payload.exp !== 'number'
    || typeof payload.iat !== 'number'
    || typeof payload.sid !== 'string'
  ) {
    return plain(
      'Payload video tidak valid.',
      403
    );
  }

  if (
    payload.exp < now
    || payload.iat > now + 120
    || payload.exp - payload.iat > 10800
  ) {
    return plain(
      'Link video sudah kedaluwarsa.',
      403
    );
  }

  const cookieValue =
    readCookie(
      request.headers.get('Cookie') || '',
      'alvp_session'
    );

  if (
    !cookieValue
    || !/^[a-f0-9]{64}$/.test(cookieValue)
  ) {
    return plain(
      'Sesi pemutar tidak valid.',
      403
    );
  }

  const sessionHash =
    await sha256Hex(cookieValue);

  if (
    !timingSafeEqual(
      sessionHash,
      payload.sid
    )
  ) {
    return plain(
      'Sesi pemutar tidak cocok.',
      403
    );
  }

  const referer =
    request.headers.get('Referer') || '';

  if (referer) {
    try {
      const refHost =
        new URL(referer).hostname.toLowerCase();

      if (
        refHost !== 'asupanlendir.sbs'
        && !refHost.endsWith(
          '.asupanlendir.sbs'
        )
      ) {
        return plain(
          'Referer tidak diizinkan.',
          403
        );
      }
    } catch {
      return plain(
        'Referer tidak valid.',
        403
      );
    }
  }

  let upstreamUrl;

  try {
    upstreamUrl =
      new URL(payload.u);
  } catch {
    return plain(
      'Origin video tidak valid.',
      403
    );
  }

  if (
    upstreamUrl.protocol !== 'https:'
    && upstreamUrl.protocol !== 'http:'
  ) {
    return plain(
      'Protocol origin tidak diizinkan.',
      403
    );
  }

  if (
    isBlockedHostname(
      upstreamUrl.hostname
    )
  ) {
    return plain(
      'Origin video tidak diizinkan.',
      403
    );
  }

  const upstreamHeaders =
    new Headers();

  copyHeader(
    request.headers,
    upstreamHeaders,
    'Range'
  );

  copyHeader(
    request.headers,
    upstreamHeaders,
    'If-Range'
  );

  copyHeader(
    request.headers,
    upstreamHeaders,
    'If-None-Match'
  );

  copyHeader(
    request.headers,
    upstreamHeaders,
    'If-Modified-Since'
  );

  copyHeader(
    request.headers,
    upstreamHeaders,
    'Accept'
  );

  upstreamHeaders.set(
    'User-Agent',
    'AsupanLendir-Secure-Video-Proxy/1.0'
  );

  let response;

  try {
    response = await fetch(
      upstreamUrl.toString(),
      {
        method,
        headers: upstreamHeaders,
        redirect: 'follow',
      }
    );
  } catch (error) {
    return plain(
      'Gagal mengambil video dari origin.',
      502
    );
  }

  const headers =
    new Headers(response.headers);

  headers.delete('Set-Cookie');
  headers.delete('Server');
  headers.delete('X-Powered-By');

  headers.set(
    'Cache-Control',
    'private, no-store, max-age=0'
  );

  headers.set(
    'X-Content-Type-Options',
    'nosniff'
  );

  headers.set(
    'Referrer-Policy',
    'no-referrer'
  );

  const origin =
    request.headers.get('Origin');

  if (
    origin === 'https://asupanlendir.sbs'
    || origin === 'https://www.asupanlendir.sbs'
  ) {
    headers.set(
      'Access-Control-Allow-Origin',
      origin
    );

    headers.set(
      'Access-Control-Allow-Credentials',
      'true'
    );

    headers.append(
      'Vary',
      'Origin'
    );
  }

  if (payload.dl === 1) {
    const safeName =
      sanitizeFilename(
        String(payload.fn || 'video.mp4')
      );

    headers.set(
      'Content-Disposition',
      `attachment; filename="${safeName}"`
    );
  } else {
    headers.delete(
      'Content-Disposition'
    );
  }

  if (method === 'HEAD') {
    return new Response(
      null,
      {
        status: response.status,
        statusText: response.statusText,
        headers,
      }
    );
  }

  return new Response(
    response.body,
    {
      status: response.status,
      statusText: response.statusText,
      headers,
    }
  );
}


async function decryptToken(
  token,
  secret
) {
  const packed =
    base64urlDecode(token);

  if (packed.length < 29) {
    throw new Error(
      'token too short'
    );
  }

  const iv =
    packed.slice(0, 12);

  /*
   * PHP packed:
   * IV || ciphertext || 16-byte GCM tag
   *
   * WebCrypto expects:
   * ciphertext || tag
   */
  const cipherAndTag =
    packed.slice(12);

  const secretBytes =
    new TextEncoder().encode(secret);

  const keyBytes =
    await crypto.subtle.digest(
      'SHA-256',
      secretBytes
    );

  const key =
    await crypto.subtle.importKey(
      'raw',
      keyBytes,
      {
        name: 'AES-GCM',
      },
      false,
      ['decrypt']
    );

  const plainBuffer =
    await crypto.subtle.decrypt(
      {
        name: 'AES-GCM',
        iv,
        tagLength: 128,
      },
      key,
      cipherAndTag
    );

  const json =
    new TextDecoder().decode(
      plainBuffer
    );

  return JSON.parse(json);
}


function base64urlDecode(value) {
  if (
    !/^[A-Za-z0-9_-]+$/.test(value)
  ) {
    throw new Error(
      'invalid base64url'
    );
  }

  let base64 =
    value
      .replace(/-/g, '+')
      .replace(/_/g, '/');

  while (base64.length % 4) {
    base64 += '=';
  }

  const binary =
    atob(base64);

  const bytes =
    new Uint8Array(binary.length);

  for (
    let i = 0;
    i < binary.length;
    i++
  ) {
    bytes[i] =
      binary.charCodeAt(i);
  }

  return bytes;
}


async function sha256Hex(value) {
  const digest =
    await crypto.subtle.digest(
      'SHA-256',
      new TextEncoder().encode(value)
    );

  return Array.from(
    new Uint8Array(digest)
  )
    .map(
      byte =>
        byte
          .toString(16)
          .padStart(2, '0')
    )
    .join('');
}


function timingSafeEqual(a, b) {
  if (
    typeof a !== 'string'
    || typeof b !== 'string'
    || a.length !== b.length
  ) {
    return false;
  }

  let diff = 0;

  for (
    let i = 0;
    i < a.length;
    i++
  ) {
    diff |=
      a.charCodeAt(i)
      ^ b.charCodeAt(i);
  }

  return diff === 0;
}


function readCookie(
  cookieHeader,
  name
) {
  const parts =
    cookieHeader.split(';');

  for (const part of parts) {
    const index =
      part.indexOf('=');

    if (index === -1) {
      continue;
    }

    const key =
      part
        .slice(0, index)
        .trim();

    if (key !== name) {
      continue;
    }

    return decodeURIComponent(
      part
        .slice(index + 1)
        .trim()
    );
  }

  return '';
}


function copyHeader(
  source,
  target,
  name
) {
  const value =
    source.get(name);

  if (value) {
    target.set(
      name,
      value
    );
  }
}


function isBlockedHostname(hostname) {
  const host =
    String(hostname)
      .toLowerCase()
      .replace(/\.$/, '');

  if (
    host === 'localhost'
    || host.endsWith('.localhost')
    || host.endsWith('.local')
    || host.endsWith('.internal')
  ) {
    return true;
  }

  /*
   * Block obvious private/loopback IPv4 literals.
   */
  const ipv4 =
    host.match(
      /^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/
    );

  if (ipv4) {
    const octets =
      ipv4
        .slice(1)
        .map(Number);

    if (
      octets.some(
        n => n < 0 || n > 255
      )
    ) {
      return true;
    }

    if (
      octets[0] === 10
      || octets[0] === 127
      || (
        octets[0] === 169
        && octets[1] === 254
      )
      || (
        octets[0] === 172
        && octets[1] >= 16
        && octets[1] <= 31
      )
      || (
        octets[0] === 192
        && octets[1] === 168
      )
    ) {
      return true;
    }
  }

  if (
    host === '::1'
    || host.startsWith('fc')
    || host.startsWith('fd')
    || host.startsWith('fe80:')
  ) {
    return true;
  }

  return false;
}


function sanitizeFilename(value) {
  let name =
    value
      .replace(
        /[\r\n"\\\/]+/g,
        ' '
      )
      .replace(
        /\s+/g,
        ' '
      )
      .trim();

  if (!name) {
    name = 'video.mp4';
  }

  if (
    !name
      .toLowerCase()
      .endsWith('.mp4')
  ) {
    name += '.mp4';
  }

  return name.slice(0, 140);
}


function corsPreflight(request) {
  const origin =
    request.headers.get('Origin') || '';

  const headers =
    new Headers({
      'Access-Control-Allow-Methods':
        'GET, HEAD, OPTIONS',
      'Access-Control-Allow-Headers':
        'Range, If-Range, Content-Type',
      'Access-Control-Max-Age':
        '600',
      'Cache-Control':
        'no-store',
    });

  if (
    origin === 'https://asupanlendir.sbs'
    || origin === 'https://www.asupanlendir.sbs'
  ) {
    headers.set(
      'Access-Control-Allow-Origin',
      origin
    );

    headers.set(
      'Access-Control-Allow-Credentials',
      'true'
    );
  }

  return new Response(
    null,
    {
      status: 204,
      headers,
    }
  );
}


function plain(
  message,
  status
) {
  return new Response(
    message,
    {
      status,
      headers: {
        'Content-Type':
          'text/plain; charset=UTF-8',
        'Cache-Control':
          'no-store',
        'X-Content-Type-Options':
          'nosniff',
      },
    }
  );
}


/* ============================================================
 * LEGACY PROXY
 * ============================================================
 *
 * This section intentionally preserves the behavior from the
 * existing proxy-master Worker.
 */

async function handleLegacyProxy(request) {
  const url =
    new URL(request.url);

  const ALLOWED_DOMAINS = [
    'videy.ca',
    'videy.cl',
    'video.pejuanglendir.site',
    'videyi.ch',
    'cdn.videyi.fun'
  ];

  const referer =
    request.headers.get('Referer') || '';

  const origin =
    request.headers.get('Origin') || '';

  let isAllowed = false;

  if (
    ALLOWED_DOMAINS.some(
      d =>
        referer.includes(d)
        || origin.includes(d)
    )
  ) {
    isAllowed = true;
  }

  if (!isAllowed) {
    return new Response(
      'Akses Ditolak! Tonton video secara resmi di Pejuang Lendir.',
      {
        status: 403,
        headers: {
          'Content-Type':
            'text/plain'
        }
      }
    );
  }

  const pathParts =
    url.pathname.split('/');

  const base64String =
    pathParts[pathParts.length - 1];

  if (!base64String) {
    return new Response(
      'URL Video Tidak Ditemukan.',
      {
        status: 400
      }
    );
  }

  let decodedVideoUrl = '';

  try {
    decodedVideoUrl =
      atob(base64String);
  } catch (e) {
    return new Response(
      'Format Enkripsi Salah.',
      {
        status: 400
      }
    );
  }

  try {
    const modifiedRequest =
      new Request(
        decodedVideoUrl,
        {
          method: request.method,
          headers: request.headers
        }
      );

    let response =
      await fetch(
        modifiedRequest
      );

    let newHeaders =
      new Headers(
        response.headers
      );

    newHeaders.set(
      'Access-Control-Allow-Origin',
      '*'
    );

    newHeaders.delete(
      'X-Powered-By'
    );

    return new Response(
      response.body,
      {
        status:
          response.status,
        statusText:
          response.statusText,
        headers:
          newHeaders
      }
    );
  } catch (e) {
    return new Response(
      'Gagal mengambil video dari server penyimpanan.',
      {
        status: 500
      }
    );
  }
}
