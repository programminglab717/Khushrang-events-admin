<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/integrations-lib.php';
require __DIR__.'/config-loader.php';
require __DIR__.'/connections.php';
$path=khushrang_config_path(__DIR__);
try{$config=khushrang_load_config($path);$db=new PDO($config['dsn'],$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$db->exec("SET time_zone='+00:00'");$config=integration_runtime_config($db,$config);$count=integration_work($db,$config);$db->exec("DELETE FROM records WHERE kind='mcp_audit' AND created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY)");$q=$db->prepare('DELETE FROM integration_rate_limits WHERE minute_bucket<?');$q->execute([(int)floor(time()/60)-1440]);connection_put($db,'integration-runtime-worker','integration_runtime',['last_run'=>gmdate('c'),'processed'=>$count]);echo "Processed $count queued deliveries.\n";}catch(Throwable $e){fwrite(STDERR,"Integration worker failed. Verify configuration and database setup.\n");exit(1);}
