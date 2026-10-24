<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/integrations-lib.php';
require __DIR__.'/config-loader.php';
$path=getenv('KHUSHRANG_CONFIG')?:dirname(__DIR__,2).'/khushrang-private/config.php';
try{$config=khushrang_load_config($path);$db=new PDO($config['dsn'],$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$db->exec("SET time_zone='+00:00'");$count=integration_work($db,$config);echo "Processed $count queued deliveries.\n";}catch(Throwable $e){fwrite(STDERR,"Integration worker failed. Verify configuration and database setup.\n");exit(1);}
