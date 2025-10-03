<?php
/*
 * Thiết lập plugin GLPI Mobile Notification
 * Ghi chú: Mọi log sẽ ghi tiếng Việt vào smartdesk_mobile.log
 */

function plugin_version_glpimobilenotification() {
  return [
    'name'           => "Glpi mobile notification",
    'version'        => '1.1.0',
    'author'         => 'mvv + SmartDesk migration',
    'license'        => '',
    'homepage'       => '',
    'minGlpiVersion' => ''
  ];
}

/* Kiểm tra cấu hình tối thiểu */
function plugin_glpimobilenotification_check_config() {
  return true;
}

/* Kiểm tra điều kiện tiên quyết */
function plugin_glpimobilenotification_check_prerequisites() {
  // Có thể bổ sung kiểm tra phiên bản GLPI khi cần
  return true;
}

/* Khởi tạo hooks của plugin (bắt buộc) */
function plugin_init_glpimobilenotification() {
  global $PLUGIN_HOOKS;

  Plugin::registerClass(PluginGlpimobilenotificationEvent::class);

  $PLUGIN_HOOKS['csrf_compliant']['glpimobilenotification'] = true;

  // Đăng ký hook tạo mới Ticket và thêm ITILFollowup
  $PLUGIN_HOOKS['item_add']['glpimobilenotification'] = [
    Ticket::class       => 'plugin_glpimobilenotification_item_add',
    ITILFollowup::class => 'plugin_glpimobilenotification_followup_add'
  ];
}
