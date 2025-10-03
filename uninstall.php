<?php
/*
 * Gỡ cài đặt plugin: xóa bảng cấu hình, xóa cột mobile_notification
 * Logger an toàn + dùng global $DB (GLPI core)
 */

$logfile = __DIR__ . "/smartdesk_mobile.log";
$log_ready = true;
if (!file_exists($logfile)) {
  if (@touch($logfile) === false) {
    @error_log("[GỠ CÀI ĐẶT] Không tạo được file log riêng: $logfile. Sẽ ghi vào php-errors.");
    $log_ready = false;
  } else {
    @chmod($logfile, 0664);
  }
}
function sm_log_u($msg) {
  global $logfile, $log_ready;
  $line = "[GỠ CÀI ĐẶT] $msg\n";
  if ($log_ready) { @error_log($line, 3, $logfile); } else { @error_log($line); }
}

global $DB; // QUAN TRỌNG: lấy kết nối DB từ GLPI core
sm_log_u("Bắt đầu gỡ cài đặt plugin.");

$a = explode("/", __DIR__);
$plugin_name       = $a[sizeof($a)-1];
$config_table_name = "glpi_plugin_".$plugin_name."_config";

$migration = new Migration(110);

// Xóa bảng cấu hình nếu tồn tại
if ($DB && $DB->tableExists($config_table_name)) {
  $query = "DROP TABLE `$config_table_name`";
  $DB->queryOrDie($query, $DB->error());
  sm_log_u("Đã xóa bảng cấu hình: $config_table_name");
} else {
  sm_log_u("Bảng cấu hình không tồn tại hoặc DB chưa sẵn sàng: $config_table_name");
}

// Xóa cột mobile_notification khỏi glpi_users nếu tồn tại
$table = "glpi_users";
$field = "mobile_notification";
if ($DB && $DB->tableExists($table)) {
  if ($DB->fieldExists($table, $field, false)) {
    $migration->dropField($table, $field);
    sm_log_u("Đã xóa cột $field khỏi bảng $table.");
  } else {
    sm_log_u("Cột $field không tồn tại trong $table.");
  }
} else {
  sm_log_u("Bảng $table không tồn tại hoặc DB chưa sẵn sàng.");
}

$migration->executeMigration();
sm_log_u("Hoàn tất gỡ cài đặt plugin.");

return true;
