<?php
/*
 * Cài đặt plugin: tạo bảng cấu hình, thêm cột token vào glpi_users, nạp tham số .cfg
 * Ghi log tiếng Việt vào smartdesk_mobile.log
 */

global $DB;

$parameters = [
  "firebase_url"                   => "",
  "api_key_android"               => "",
  "api_key_ios"                   => "",
  "message_title"                 => "",
  "message_lang"                  => "",
  "actor_types"                   => "",
  "admin_profiles"                => "",
  "write_log"                     => "",
  "log_file"                      => "smartdesk_mobile.log",
  // Tham số FCM v1
  "fcm_v1_enabled"                => "0",
  "fcm_project_id"                => "",
  "fcm_access_token"              => "",
  "fcm_access_token_expires_at"   => ""
];

// Cột trong glpi_users để lưu token app mobile
$table = "glpi_users";
$field = "mobile_notification";
$type  = "VARCHAR(255)";

// Tên plugin và bảng config
$a = explode("/", __DIR__);
$plugin_name       = $a[sizeof($a)-1];
$config_table_name = "glpi_plugin_".$plugin_name."_config";
$configfile        = __DIR__."/".$plugin_name.".cfg";
$logfile           = __DIR__."/smartdesk_mobile.log"; // log file cố định theo yêu cầu

// Đảm bảo file log tồn tại
if (!file_exists($logfile)) {
  touch($logfile);
  // Phân quyền phù hợp người chạy webserver
}

$logfile = __DIR__ . "/smartdesk_mobile.log"; // đường dẫn tuyệt đối, không để rỗng
if (!file_exists($logfile)) {
  // Tạo file log nếu chưa tồn tại
  if (@touch($logfile) === false) {
    // Fallback: ghi vào php-errors nếu không tạo được file riêng
    error_log("[CÀI ĐẶT] Không tạo được file log riêng: $logfile. Sẽ ghi vào php-errors.log.");
    $logfile = null; // ép sm_log ghi vào error_log mặc định
  } else {
    // Cố gắng set quyền ghi (tùy hệ thống)
    @chmod($logfile, 0664);
  }
}

// Logger an toàn
function sm_log($msg) {
  global $logfile;
  $line = "[CÀI ĐẶT] $msg\n";
  if (!empty($logfile)) {
    // Ghi trực tiếp vào file riêng
    @error_log($line, 3, $logfile);
  } else {
    // Ghi vào php-errors mặc định
    @error_log($line);
  }
}


sm_log("Bắt đầu cài đặt plugin.");

// Đọc cấu hình từ .cfg
$fd = @fopen($configfile, 'r');
if (!$fd) {
  sm_log("LỖI: Không thể mở file cấu hình: ".$configfile);
  die("Không thể mở file cấu hình: ".$configfile);
}
while(!feof($fd)) {
  $str = trim(fgets($fd));
  if ($str === "" || strpos($str, "=") === false) continue;
  $kv = explode("=", $str, 2);
  $k = trim($kv[0]);
  $v = trim($kv[1]);
  if (array_key_exists($k, $parameters)) {
    $parameters[$k] = $v;
  }
}
fclose($fd);

// Kiểm tra tham số tối thiểu
$required = ["firebase_url","message_title","actor_types","admin_profiles","write_log"];
foreach($required as $k) {
  if ($parameters[$k] === "") {
    sm_log("LỖI: Thiếu tham số bắt buộc: ".$k);
    die("Thiếu tham số bắt buộc: ".$k.". Kiểm tra file cấu hình.");
  }
}

sm_log("Đã đọc tham số cấu hình từ .cfg");

// Migration
$migration = new Migration(110);

// Tạo bảng config nếu chưa có
if (!$DB->tableExists($config_table_name)) {
  $query = "CREATE TABLE $config_table_name (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `par_name` VARCHAR(255) NOT NULL,
    `par_value` VARCHAR(500) NOT NULL
  )";
  $DB->queryOrDie($query, $DB->error());
  sm_log("Đã tạo bảng cấu hình: ".$config_table_name);
} else {
  // Xóa tham số cũ để ghi lại sạch
  $DB->queryOrDie("TRUNCATE TABLE $config_table_name", $DB->error());
  sm_log("Đã dọn bảng cấu hình cũ.");
}

// Ghi tham số
foreach(array_keys($parameters) as $key) {
  $v = $DB->escape($parameters[$key]);
  $q = "INSERT INTO $config_table_name (par_name, par_value) VALUES ('$key', '$v')";
  $DB->queryOrDie($q, $DB->error());
}
sm_log("Đã ghi tham số cấu hình.");

// Ghi tham số log_file cố định
$q = "INSERT INTO $config_table_name (par_name, par_value) VALUES ('log_file','$logfile')";
$DB->queryOrDie($q, $DB->error());
sm_log("Thiết lập file log: ".$logfile);

// Thêm cột mobile_notification nếu chưa tồn tại
if ($DB->tableExists($table)) {
  if (!$DB->fieldExists($table, $field, false)) {
    $migration->addField($table, $field, $type);
    sm_log("Đã thêm cột $field vào bảng $table.");
  } else {
    sm_log("Cột $field đã tồn tại trong $table.");
  }
}

$migration->executeMigration();
sm_log("Hoàn tất cài đặt plugin.");
