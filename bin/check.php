<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
echo 'PHP: '.PHP_VERSION.PHP_EOL;
foreach(['pdo_sqlite','curl','openssl','SimpleXML','session','json'] as $ext)echo $ext.': '.(extension_loaded($ext)?'OK':'MISSING').PHP_EOL;
echo '64-bit: '.(PHP_INT_SIZE>=8?'OK':'MISSING').PHP_EOL;
echo 'var writable: '.(is_writable(__DIR__.'/../var')?'OK':'NO').PHP_EOL;
echo 'config.php: '.(is_file(__DIR__.'/../config.php')?'OK':'COPY config.example.php FIRST').PHP_EOL;
