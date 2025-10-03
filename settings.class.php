<?php
class PluginGlpimobilenotificationSettings extends CommonDBTM {

  public function showForm($ID, $options = []) {
    $this->initForm($ID, $options);
    $this->showFormHeader($options);

    echo "<div style='padding:8px'>";
    echo "<h3>GLPI Mobile Notification - Thiết lập</h3>";
    echo "<p>Plugin hỗ trợ gửi thông báo đẩy qua Firebase (Legacy và HTTP v1).</p>";
    echo "<p>Nhật ký hoạt động được ghi tại: plugins/glpimobilenotification/smartdesk_mobile.log</p>";
    echo "<p>Vui lòng chỉnh sửa file cấu hình glpimobilenotification.cfg và cài đặt lại plugin nếu thay đổi tham số.</p>";
    echo "</div>";

    $this->showFormButtons($options);
  }
}
