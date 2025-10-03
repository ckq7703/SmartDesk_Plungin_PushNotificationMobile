<?php
/*
 * Hook cài đặt/gỡ cài đặt plugin
 * Log chi tiết tiếng Việt vào smartdesk_mobile.log
 */

function plugin_glpimobilenotification_install() {
  require_once 'install.php';
  return true;
}

function plugin_glpimobilenotification_uninstall() {
  require_once 'uninstall.php';
  return true;
}

/* Bắn sự kiện khi tạo Ticket */
function plugin_glpimobilenotification_item_add(Ticket $ticket) {
  return PluginGlpimobilenotificationEvent::item_add_ticket($ticket);
}

/* Bắn sự kiện khi thêm Followup */
function plugin_glpimobilenotification_followup_add(ITILFollowup $followup) {
  return PluginGlpimobilenotificationEvent::item_add_followup($followup);
}
