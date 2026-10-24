<?php
declare(strict_types=1);
require __DIR__.'/../server/config-loader.php';
function verify(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
$dir=sys_get_temp_dir().'/khushrang-config-'.bin2hex(random_bytes(6));mkdir($dir,0700);
$keys=['DB_HOST','DB_PORT','DB_NAME','DB_USER','DB_PASSWORD','STORAGE_PATH','KHUSHRANG_ENV_FILE'];$saved=[];
foreach($keys as $key){$saved[$key]=getenv($key);putenv($key);}
try{
 $env="DB_HOST=localhost\nDB_PORT=3306\nDB_NAME=example_db\nDB_USER=example_user\nDB_PASSWORD='literal # dollar\$ backslash\\ value'\nSTORAGE_PATH=/private/media\n";
 file_put_contents($dir.'/.env',$env);chmod($dir.'/.env',0600);
 $c=khushrang_load_config($dir.'/config.php');verify($c['dsn']==='mysql:host=localhost;port=3306;dbname=example_db;charset=utf8mb4','Env-only DSN failed');verify($c['password']==='literal # dollar$ backslash\\ value','Password literal damaged');verify($c['storage_path']==='/private/media','Storage mapping failed');
 putenv('DB_PASSWORD=runtime-override');verify(khushrang_load_config($dir.'/config.php')['password']==='runtime-override','Runtime precedence failed');putenv('DB_PASSWORD');
 file_put_contents($dir.'/config.php',"<?php return ['dsn'=>'legacy-dsn','user'=>'legacy','password'=>'legacy','storage_path'=>'/old','inbound_connectors'=>[['source'=>'keep-me']]];");verify(khushrang_load_config($dir.'/config.php')['inbound_connectors'][0]['source']==='keep-me','Integration settings lost');
 unlink($dir.'/.env');verify(khushrang_load_config($dir.'/config.php')['dsn']==='legacy-dsn','Legacy compatibility failed');
 file_put_contents($dir.'/.env',"STORAGE_PATH=/new/private/media\n");verify(khushrang_load_config($dir.'/config.php')['dsn']==='legacy-dsn','Storage-only override damaged DSN');
 file_put_contents($dir.'/.env',"DB_HOST=localhost\nDB_NAME=example_db;host=unsafe\nDB_USER=test\nDB_PASSWORD=unprinted-test-value\n");
 try{khushrang_load_config($dir.'/config.php');throw new LogicException('Unsafe DB name accepted');}catch(RuntimeException $e){verify(!str_contains($e->getMessage(),'unprinted-test-value'),'Error reveals credentials');}
 file_put_contents($dir.'/.env',"DUP=one\nDUP=two\n");try{khushrang_read_env($dir.'/.env');throw new LogicException('Duplicate keys accepted');}catch(RuntimeException $e){}
 file_put_contents($dir.'/.env',"DB_PASSWORD=\"unterminated\n");try{khushrang_read_env($dir.'/.env');throw new LogicException('Malformed quotes accepted');}catch(RuntimeException $e){}
 echo "PASS: dotenv-only config, quoted special characters, runtime precedence, legacy compatibility, integration preservation, storage override, invalid DB name, duplicate keys, malformed quoting; no credential output.\n";
}finally{foreach($keys as $key)putenv($saved[$key]===false?$key:$key.'='.$saved[$key]);foreach(glob($dir.'/*') as $file)unlink($file);if(is_file($dir.'/.env'))unlink($dir.'/.env');rmdir($dir);}
