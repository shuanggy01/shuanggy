"""CGI integration tests, isolated temporary installation. Run with PHP_CGI=php-cgi python3 tests/integration.py."""
import os, pathlib, tempfile, shutil, subprocess, json, re, urllib.parse, sqlite3
ROOT=pathlib.Path(__file__).resolve().parents[1]
PHP=os.environ.get('PHP_CGI','php-cgi')
with tempfile.TemporaryDirectory(prefix='player-test-') as tmp:
    project=pathlib.Path(tmp)/'app';shutil.copytree(ROOT,project)
    sessions=pathlib.Path(tmp)/'sessions';sessions.mkdir()
    (project/'config.php').write_text("<?php return ['setup_token'=>'test-setup-token-12345678901234567890','data_dir'=>__DIR__.'/var'];")
    cookie='';count=0
    def request(script,method='GET',data=None,query='',csrf=None):
        global cookie,count
        body=json.dumps(data).encode() if script=='api.php' and data is not None else urllib.parse.urlencode(data or {}).encode()
        env={**os.environ,'REDIRECT_STATUS':'200','REQUEST_METHOD':method,'SCRIPT_FILENAME':str(project/'public'/script),'SCRIPT_NAME':'/'+script,'DOCUMENT_ROOT':str(project/'public'),'SERVER_NAME':'localhost','SERVER_PORT':'80','SERVER_PROTOCOL':'HTTP/1.1','GATEWAY_INTERFACE':'CGI/1.1','REMOTE_ADDR':'127.0.0.1','QUERY_STRING':query,'CONTENT_LENGTH':str(len(body)),'CONTENT_TYPE':'application/json' if script=='api.php' else 'application/x-www-form-urlencoded','HTTP_COOKIE':cookie}
        if csrf:env['HTTP_X_CSRF_TOKEN']=csrf
        p=subprocess.run([PHP,'-d','session.save_path='+str(sessions)],input=body,env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,check=True)
        assert not p.stderr,p.stderr.decode()
        raw=p.stdout.decode();headers,content=raw.split('\r\n\r\n',1)
        for line in headers.splitlines():
            if line.lower().startswith('set-cookie:'):cookie=line.split(': ',1)[1].split(';')[0]
        status=int(re.search(r'Status: (\d+)',headers).group(1)) if 'Status:' in headers else 200
        count+=1
        return status,content,headers
    def api(action,extra=None,token=None):
        status,text,_=request('api.php','POST',{'action':action,**(extra or {})},csrf=token or csrf)
        return status,json.loads(text)
    status,body,_=request('admin.php');assert status==200 and 'Siapkan studio' in body
    csrf=re.search(r'name="csrf" content="([a-f0-9]+)"',body).group(1)
    assert api('settings',token='bad')[0]==403
    assert api('settings')[0]==401
    s,b,_=request('admin.php','POST',{'csrf':csrf,'action':'setup','username':'creator','password':'a-strong-test-password','token':'wrong'});assert 'Isi setup_token' in b
    s,_,hdr=request('admin.php','POST',{'csrf':csrf,'action':'setup','username':'creator','password':'a-strong-test-password','token':'test-setup-token-12345678901234567890'});assert s==302 and 'Location: admin.php' in hdr
    s,b,_=request('admin.php');assert 'Ruang video abang' in b and 'CREATE TABLE' not in b
    assert api('settings',{'site_name':'Test <b>','vip_url':'javascript:alert(1)'})[0]==400
    ads=[{'slot':x,'enabled':True,'type':'video' if x=='preroll' else 'image','url':'https://example.com/ad.mp4' if x=='preroll' else 'https://example.com/ad.jpg','link':'https://example.com','seconds':2,'html':''} for x in ['before1','before2','before3','preroll','pause']]
    assert api('settings',{'site_name':'Test <b>','vip_url':'https://example.com/vip','download':True,'ads':ads})[0]==200
    s,b,_=request('index.php');assert 'Test &lt;b&gt;' in b and 'Test <b>' not in b
    assert api('upload_start',{'kind':'video','name':'bad.php','size':20})[0]==400
    assert api('upload_start',{'kind':'video','name':'good.mp4','size':2147483649})[0]==400
    assert api('upload_start',{'kind':'video','name':'good.mp4','size':1024})[0]==400 # R2 unconfigured
    assert api('r2_save',{'account':'invalid','bucket':'bucket','public':'https://example.com','access':'test-key','secret':'test-secret'})[0]==400
    assert api('r2_save',{'account':'a'*32,'bucket':'test-bucket','public':'https://cdn.example.com','access':'test-key','secret':'test-secret'})[0]==200
    db=sqlite3.connect(project/'var/player.sqlite');stored=db.execute("SELECT value FROM settings WHERE key='r2'").fetchone()[0];assert 'test-secret' not in stored and 'test-key' not in stored
    s,b,_=request('admin.php');assert 'test-secret' not in b and 'test-key' not in b
    db.execute('INSERT INTO videos VALUES(?,?,?,?,?,?,?,?)',('deadbeef','Test <script>alert(1)</script>','videos/a.mp4','thumbnails/a.jpg','https://cdn.example.com/videos/a.mp4','https://cdn.example.com/thumbnails/a.jpg',0,1));db.commit()
    s,b,_=request('index.php',query='v=deadbeef');assert s==200 and '<script>alert(1)</script>' not in b and 'id="player-data"' in b
    assert api('view',{'id':'deadbeef'})[0]==200
    assert api('view',{'id':'deadbeef'})[0]==200
    assert db.execute("SELECT views FROM videos WHERE id='deadbeef'").fetchone()[0]==1
    assert request('index.php',query='v=missing')[0]==404
    s,_,hdr=request('download.php',query='v=deadbeef');assert s==302 and 'X-Amz-Signature' in hdr and 'test-secret' not in hdr
    assert api('video_edit',{'id':'deadbeef','title':'New title'})[0]==200
    assert db.execute("SELECT title FROM videos WHERE id='deadbeef'").fetchone()[0]=='New title'
    assert api('password',{'current':'wrong','password':'new-strong-password'})[0]==400
    assert api('password',{'current':'a-strong-test-password','password':'new-strong-password'})[0]==200
    assert api('video_delete',{'id':'deadbeef'})[0]==200
    assert request('index.php',query='v=deadbeef')[0]==404
    assert request('admin.php','POST',{'csrf':csrf,'action':'logout'})[0]==302
    _,b,_=request('admin.php');csrf=re.search(r'name="csrf" content="([a-f0-9]+)"',b).group(1)
    s,b,_=request('admin.php','POST',{'csrf':csrf,'action':'login','username':'creator','password':'a-strong-test-password'});assert 'password salah' in b
    s,_,_=request('admin.php','POST',{'csrf':csrf,'action':'login','username':'creator','password':'new-strong-password'});assert s==302
    # HTML advertisements receive sandbox CSP; the player itself never embeds raw scripts.
    ads[0].update(type='html',html='<script>document.write("Ad")</script>')
    assert api('settings',{'site_name':'Test','ads':ads})[0]==200
    s,b,headers=request('ad.php',query='slot=before1');assert s==200 and 'Content-Security-Policy: sandbox ' in headers and 'allow-same-origin' not in headers
    print(f'PASS: {count} CGI requests; setup/login, CSRF/auth, settings, secret encryption, upload validation, video pages/views, download signature, edit/delete, password change, ad sandbox.')
