<?php
/** Server-only dotenv reader. No interpolation, shell evaluation, or browser exposure. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&!defined('KHUSHRANG_INTERNAL')){http_response_code(404);exit;}
function khushrang_read_env(string $path): array {
    if(!is_file($path))return [];
    if(!is_readable($path))throw new RuntimeException('Private environment file is not readable.');
    $lines=file($path,FILE_IGNORE_NEW_LINES);if($lines===false)throw new RuntimeException('Could not read private environment file.');
    $values=[];
    foreach($lines as $number=>$line){
        $line=trim($line);if($line===''||str_starts_with($line,'#'))continue;
        if(!preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/',$line,$m))throw new RuntimeException('Invalid environment syntax at line '.($number+1).'.');
        $value=$m[2];$first=$value[0]??'';
        if($first==='"'||$first==="'"){
            if(strlen($value)<2||substr($value,-1)!==$first)throw new RuntimeException('Unclosed environment quote at line '.($number+1).'.');
            $value=substr($value,1,-1);
            if($first==='"')$value=strtr($value,['\\\\'=>'\\','\\"'=>'"','\\n'=>"\n",'\\r'=>"\r",'\\t'=>"\t"]);
        } else {
            // Inline comments require preceding whitespace. A literal # in a token is preserved.
            $value=rtrim(preg_replace('/\s+#.*$/','',$value));
        }
        if(array_key_exists($m[1],$values))throw new RuntimeException('Duplicate environment key '.$m[1].'.');
        $values[$m[1]]=$value;
    }
    return $values;
}
function khushrang_load_config(string $configPath): array {
    $envPath=getenv('KHUSHRANG_ENV_FILE')?:dirname($configPath).'/.env';
    if(!is_file($configPath)&&!is_file($envPath))throw new RuntimeException('Private configuration is missing.');
    $config=is_file($configPath)?require $configPath:[];
    if(!is_array($config))throw new RuntimeException('Private configuration must return an array.');
    $file=khushrang_read_env($envPath);
    // Runtime variables take precedence, followed by .env, followed by legacy config.php.
    $read=static function(string $key)use($file):?string{$runtime=getenv($key);return $runtime!==false?$runtime:($file[$key]??null);};
    $dbKeys=['DB_HOST','DB_PORT','DB_NAME','DB_USER','DB_PASSWORD'];$hasDb=false;
    foreach($dbKeys as $key)if($read($key)!==null)$hasDb=true;
    if($hasDb){
        $host=$read('DB_HOST')??'localhost';$port=$read('DB_PORT')??'3306';$name=$read('DB_NAME');$user=$read('DB_USER');$password=$read('DB_PASSWORD');
        if(!preg_match('/^[A-Za-z0-9.:-]+$/',$host))throw new RuntimeException('Invalid DB_HOST.');
        if(!preg_match('/^[0-9]+$/',$port)||(int)$port<1||(int)$port>65535)throw new RuntimeException('Invalid DB_PORT.');
        if($name===null||!preg_match('/^[A-Za-z0-9_-]+$/',$name))throw new RuntimeException('DB_NAME is missing or invalid.');
        if($user===null||$user==='')throw new RuntimeException('DB_USER is required.');
        if($password===null)throw new RuntimeException('DB_PASSWORD is required.');
        $config['dsn']='mysql:host='.$host.';port='.$port.';dbname='.$name.';charset=utf8mb4';$config['user']=$user;$config['password']=$password;
    }
    $storage=$read('STORAGE_PATH');if($storage!==null)$config['storage_path']=$storage;
    if(empty($config['storage_path'])||!str_starts_with($config['storage_path'],'/'))throw new RuntimeException('STORAGE_PATH must be an absolute server filesystem path.');
    foreach(['dsn','user','password'] as $key)if(!array_key_exists($key,$config))throw new RuntimeException('Database configuration is incomplete.');
    $config['inbound_connectors']??=[];$config['email_destination']??=[];
    return $config;
}
