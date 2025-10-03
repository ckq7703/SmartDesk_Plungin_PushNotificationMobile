<?php
/*
 * Lớp xử lý sự kiện gửi thông báo đẩy GLPI Mobile qua Firebase FCM HTTP v1
 * - Chỉ dùng HTTP v1 (không dùng legacy)
 * - Payload theo chuẩn v1: message.token + notification + data (string map)
 * - Access token (Bearer) được cấp từ dịch vụ bên ngoài và nạp vào cấu hình
 * - Ghi log tiếng Việt, rõ ràng; file log được đặt qua tham số cấu hình (getparams)
 */

class PluginGlpimobilenotificationEvent extends CommonDBTM
{
    /* ====== Cấu hình/chìa khóa ====== */

    // Bảng cấu hình plugin
    static $CONFIG_TABLE = "glpi_plugin_glpimobilenotification_config"; // [GLPI yêu cầu lưu cấu hình plugin trong bảng riêng][web:143][web:22]

    // Tiền tố nhận diện token trong trường glpi_users.mobile_notification
    static $TOKEN_PREFIX_ANDROID = "FBT:";  // Android FCM token được app lưu với tiền tố này[web:5][web:7]
    static $TOKEN_PREFIX_IOS     = "FTIOS:"; // iOS FCM token được app lưu với tiền tố này[web:5][web:7]

    // Mã ngôn ngữ cho message mặc định (giữ tương thích dữ liệu cũ)
    static $MESSAGE_LANG_RUS     = "VN"; // Nếu cấu hình "rus" sẽ dùng thông điệp tiếng Nga cho phù hợp ngữ cảnh cũ[web:7]

    // Khóa data đẩy xuống app (client Flutter đọc để điều hướng)
    static $ID_KEY   = "ticketid";     // Khóa id ticket[web:7]
    static $NAME_KEY = "name";         // Tên ticket hoặc tên entity theo ngữ cảnh[web:7]
    static $TYPE_KEY = "objecttype";   // "ticket" hoặc "followup" để app phân loại[web:7]

    // Tham số nội dung thông báo
    static $MESSAGE_TITLE   = "";     // Tiêu đề hiển thị trên notification do server quy định[web:7]
    static $MESSAGE_LANG    = "";     // Ngôn ngữ thông điệp (en/rus...), quyết định chuỗi body[web:7]

    // Tham số chọn đối tượng nhận
    static $ACTOR_TYPES     = "";     // Loại actor khi followup private (ví dụ "2" là technician)[web:7]
    static $ADMIN_PROFILES  = "";     // Tập profile admin/super-admin trong entity (ví dụ "3,4,6")[web:7]

    // Ghi log
    static $WRITE_LOG       = "";     // "1" bật ghi log; chuyển error_log về file cấu hình[web:5]
    static $LOG_FILE        = "";     // Đường dẫn file log do plugin chỉ định (có thể là smartdesk_mobile.log)[web:5]
    static $LOG_FILE_OLD    = "";     // Lưu lại error_log hiện tại để khôi phục sau mỗi sự kiện[web:7]
    static $WRITE_LOG_OLD   = false;  // Lưu lại trạng thái log_errors hiện tại để khôi phục sau[web:7]

    // Cấu hình FCM HTTP v1 (bắt buộc dùng)
    static $FCM_V1_ENABLED              = "1";  // Luôn bật v1, không dùng legacy nữa[web:85][web:86]
    static $FCM_PROJECT_ID              = "";   // Firebase Project ID dùng để build endpoint v1[web:86]
    static $FCM_ACCESS_TOKEN            = "";   // OAuth2 Bearer token (không kèm "Bearer ") cấp từ dịch vụ ngoài[web:86]
    static $FCM_ACCESS_TOKEN_EXPIRES_AT = "";   // UNIX timestamp hết hạn token; nếu sắp hết hạn sẽ báo lỗi[web:86]
    static $FIREBASE_URL    = "";
    static $API_KEY_ANDROID = "";
    static $API_KEY_IOS     = "";

    /* ====== Xử lý sự kiện GLPI ====== */

    // Sự kiện: tạo mới Ticket
    static function item_add_ticket(Ticket $ticket)
    {
        self::getparams(); // Nạp cấu hình từ DB để có LOG, PROJECT_ID, TOKEN...[web:5]
        global $DB; // Sử dụng DB để truy vấn entity và recipients[web:7]

        // Chọn chuỗi thông điệp theo ngôn ngữ cấu hình
        $message = "New ticket %d"; // Tiếng Anh mặc định[web:7]
        if (strtoupper(self::$MESSAGE_LANG) == self::$MESSAGE_LANG_RUS) {
            $message = "%d Đã được tạo và ghi nhận vào hệ thống"; // Tiếng Nga tương thích cũ[web:7]
        }

        // Lấy trường của ticket
        $t = $ticket->fields; // GLPI cấp mảng fields với id/name/entity/recipient[web:7]
        $ticketId        = (int)$t['id'];
        $ticketname      = (string)$t['name'];
        $ticketentity    = (int)$t['entities_id'];
        $ticketrecipient = (int)$t['users_id_recipient']; // Tác giả ticket để loại trừ khi gửi[web:7]

        // Lấy tên entity của ticket (phục vụ log hoặc hiển thị)
        $ticketentityname = "?";
        foreach ($DB->request("SELECT name FROM glpi_entities WHERE id=".$ticketentity) as $row) {
            $ticketentityname = $row['name']; // Tên entity phục vụ ngữ cảnh[web:7]
        }

        // Lấy danh sách id entity: bao gồm entity của ticket và các entity cha (theo completename)
        $entities = self::getEntities($ticketentity); // Chuỗi id phân tách dấu phẩy để dùng IN()[web:7]

        // Xây recipients: admin profiles trong các entity đó + các actor của ticket, trừ tác giả ticket[web:7]
        $sql = "
            SELECT u.id, u.mobile_notification
            FROM glpi_users u
            JOIN glpi_profiles_users pu ON u.id = pu.users_id
            WHERE u.id <> $ticketrecipient
              AND u.mobile_notification > ''
              AND pu.profiles_id IN (".self::$ADMIN_PROFILES.")
              AND pu.entities_id IN ($entities)
            UNION
            SELECT u.id, u.mobile_notification
            FROM glpi_users u
            JOIN glpi_tickets_users tu ON u.id = tu.users_id
            WHERE u.id <> $ticketrecipient
              AND u.mobile_notification > ''
              AND tu.tickets_id = $ticketId
              AND tu.type IN (".self::$ACTOR_TYPES.")
        ";
        $users = $DB->request($sql); // Tập người nhận cùng với mobile_notification chứa prefix và token[web:7]

        if (sizeof($users) > 0) {
            // Nội dung notification
            $notification = [
                "title" => self::$MESSAGE_TITLE,           // Tiêu đề từ cấu hình[web:7]
                "body"  => sprintf($message, $ticketId)  // Đúng thứ tự format
            ];
            // Data để app điều hướng
            $data = [
                self::$ID_KEY   => $ticketId,              // ID dạng số nhưng sẽ ép string khi gửi v1[web:7][web:86]
                self::$NAME_KEY => $ticketname,            // Tên ticket[web:7]
                self::$TYPE_KEY => "ticket"                // Phân loại để client xử lý[web:7]
            ];

            // 1) Bối cảnh
        error_log("[DEBUG][Ticket#$ticketId] entities=$entities; author_id=$ticketrecipient");

        // 2) Ứng viên admin_profiles theo entity
        $sqlAdmin = "SELECT u.id, u.mobile_notification, pu.profiles_id, pu.entities_id
                    FROM glpi_users u
                    JOIN glpi_profiles_users pu ON u.id=pu.users_id
                    WHERE u.id <> $ticketrecipient
                    AND u.mobile_notification <> ''
                    AND pu.profiles_id IN (".self::$ADMIN_PROFILES.")
                    AND pu.entities_id IN ($entities)";
        $admins = $DB->request($sqlAdmin);
        foreach ($admins as $r) {
        error_log("[DEBUG][Ticket#$ticketId][ADMIN] user_id={$r['id']}, profile_id={$r['profiles_id']}, entity_id={$r['entities_id']}, token={$r['mobile_notification']}");
        }

        // 3) Ứng viên actors theo ticket
        $sqlActors = "SELECT u.id, u.mobile_notification, tu.type
                    FROM glpi_users u
                    JOIN glpi_tickets_users tu ON u.id=tu.users_id
                    WHERE u.id <> $ticketrecipient
                    AND u.mobile_notification <> ''
                    AND tu.tickets_id=$ticketId
                    AND tu.type IN (".self::$ACTOR_TYPES.")";
        $actors = $DB->request($sqlActors);
        foreach ($actors as $r) {
        error_log("[DEBUG][Ticket#$ticketId][ACTOR] user_id={$r['id']}, type={$r['type']}, token={$r['mobile_notification']}");
        }

        // 4) Hợp nhất và lọc theo prefix
        $prefixes = [ self::$TOKEN_PREFIX_ANDROID, self::$TOKEN_PREFIX_IOS ];
        $deviceTokens = [];
        $considered = array_merge(iterator_to_array($admins), iterator_to_array($actors));
        if (empty($considered)) {
        error_log("[DEBUG][Ticket#$ticketId] Không có ứng viên sau 2 nhóm (admin/actor).");
        }
        foreach ($considered as $r) {
        $uid = $r['id'];
        $raw = $r['mobile_notification'] ?? '';
        if ($raw === '') { error_log("[DEBUG][Ticket#$ticketId] user $uid bị loại: mobile_notification rỗng"); continue; }
        $matched = false;
        foreach ($prefixes as $pf) {
            if (strpos($raw, $pf) !== false) { $matched = true; break; }
        }
        if (!$matched) { error_log("[DEBUG][Ticket#$ticketId] user $uid bị loại: token không có prefix hợp lệ"); continue; }
        $parts = explode(':', $raw, 2);
        if (sizeof($parts) !== 2 || trim($parts[1]) === '') {
            error_log("[DEBUG][Ticket#$ticketId] user $uid bị loại: token không tách được phần thân");
            continue;
        }
        $token = trim($parts[1]);
        $deviceTokens[] = ['user_id'=>$uid, 'token'=>$token];
        }

        // 5) Kết quả
        if (empty($deviceTokens)) {
        error_log("[DEBUG][Ticket#$ticketId] Không còn token hợp lệ để gửi.");
        } else {
        $uids = array_map(fn($x)=>$x['user_id'], $deviceTokens);
        error_log("[DEBUG][Ticket#$ticketId] Sẽ gửi tới user_id=".implode(',', $uids)." (".count($uids)." người).");
        }

            // Gửi thông báo qua FCM v1
            self::send_event_v1($users, $notification, $data, "Tạo mới Ticket #$ticketId"); // Log tiếng Việt rõ ràng[web:86]
        } else {
            error_log("[THÔNG BÁO] Ticket #$ticketId: Không có người nhận đủ điều kiện (không gửi)."); // Log tiếng Việt[web:7]
        }

        // Khôi phục cấu hình error_log sau khi xử lý nếu có bật write_log
        if (self::$WRITE_LOG == "1") {
            ini_set("log_errors", self::$WRITE_LOG_OLD); // Trả lại cài đặt cũ[web:7]
            ini_set('error_log', self::$LOG_FILE_OLD);   // Trả lại file log cũ[web:7]
        }
    }

    // Sự kiện: thêm Followup vào Ticket
    static function item_add_followup(ITILFollowup $followup)
    {
        self::getparams(); // Nạp cấu hình từ DB để có LOG, PROJECT_ID, TOKEN...[web:5]
        global $DB;

        $f = $followup->fields; // Thông tin followup (itemtype/items_id/users_id/is_private)[web:7]
        if ($f['itemtype'] !== "Ticket") {
            // Chỉ xử lý followup cho Ticket
            if (self::$WRITE_LOG == "1") {
                error_log("[THÔNG BÁO] Followup: Bỏ qua do itemtype không phải Ticket."); // Log tiếng Việt[web:7]
            }
            return;
        }

        // Chuỗi nội dung theo ngôn ngữ
        $message = "New followup to ticket %d"; // Mặc định tiếng Anh[web:7]
        if (strtoupper(self::$MESSAGE_LANG) == self::$MESSAGE_LANG_RUS) {
            $message = "Ticket #%d vừa nhận được một phản hồi mới";
        }

        // Lấy tham số followup
        $ticketId   = (int)$f['items_id'];
        $authorId   = (int)$f['users_id'];     // Người viết followup (loại trừ khỏi nhận)[web:7]
        $isPrivate  = (int)$f['is_private'];   // Private comment => lọc theo ACTOR_TYPES[web:7]

        // Xác định entity và tên entity của ticket (để thêm ngữ cảnh data nếu cần)
        $ticketentityname = "?";
        $ticketentity     = 0;
        $q = "
            SELECT e.name, e.id
            FROM glpi_entities e
            JOIN glpi_tickets t ON t.entities_id = e.id
            WHERE t.id = $ticketId
        ";
        foreach ($DB->request($q) as $row) {
            $ticketentityname = $row['name']; // Tên entity phục vụ ngữ cảnh[web:7]
            $ticketentity     = (int)$row['id']; // ID entity để truy xuất các entity cha[web:7]
        }

        // Lấy entity kèm các entity cha
        $entities = self::getEntities($ticketentity); // Chuỗi id entity phân tách bởi dấu phẩy[web:7]

        // Xây recipients: admin profiles trong entities + actors của ticket (trừ author),
        // nếu private thì chỉ gửi cho type trong ACTOR_TYPES[web:7]
        $sql = "
            SELECT u.id, u.mobile_notification
            FROM glpi_users u
            JOIN glpi_profiles_users pu ON u.id = pu.users_id
            WHERE u.id <> $authorId
              AND u.mobile_notification > ''
              AND pu.profiles_id IN (".self::$ADMIN_PROFILES.")
              AND pu.entities_id IN ($entities)
            UNION
            SELECT u.id, u.mobile_notification
            FROM glpi_users u
            JOIN glpi_tickets_users tu ON u.id = tu.users_id
            WHERE u.id <> $authorId
              AND u.mobile_notification > ''
              AND tu.tickets_id = $ticketId
              AND ($isPrivate = 0 OR tu.type IN (".self::$ACTOR_TYPES."))
        ";
        $users = $DB->request($sql); // Trả về tập người nhận với token[web:7]

        if (sizeof($users) > 0) {
            $notification = [
                "title" => self::$MESSAGE_TITLE,               // Tiêu đề thông báo do cấu hình[web:7]
                "body"  => sprintf($message, $ticketId)  // Đúng thứ tự format
            ];
            $data = [
                self::$ID_KEY   => $ticketId,                  // Ticket ID (sẽ ép thành string khi gửi)[web:7][web:86]
                self::$NAME_KEY => $ticketentityname,          // Tên entity để client hiển thị[web:7]
                self::$TYPE_KEY => "followup"                  // Phân loại để client xử lý[web:7]
            ];

            self::send_event_v1($users, $notification, $data, "Followup mới cho Ticket #$ticketId"); // Gửi qua v1[web:86]
        } else {
            error_log("[THÔNG BÁO] Followup Ticket #$ticketId: Không có người nhận đủ điều kiện (không gửi)."); // Log tiếng Việt[web:7]
        }

        if (self::$WRITE_LOG == "1") {
            ini_set("log_errors", self::$WRITE_LOG_OLD);  // Khôi phục log_errors cũ[web:7]
            ini_set('error_log', self::$LOG_FILE_OLD);    // Khôi phục file log cũ[web:7]
        }
    }

    /* ====== Gửi FCM HTTP v1 (duy nhất) ====== */

    // Lấy access token từ cấu hình (cấp bởi dịch vụ ngoài) và kiểm tra hạn
    protected static function getExternalAccessToken()
    {
        $now   = time();                                    // Thời điểm hiện tại để so sánh hạn[web:86]
        $token = trim(self::$FCM_ACCESS_TOKEN);             // Token OAuth2 không kèm "Bearer "[web:86]
        if ($token === "") {
            throw new Exception("Thiếu FCM_ACCESS_TOKEN (HTTP v1 bắt buộc)."); // Báo lỗi rõ ràng tiếng Việt[web:86]
        }
        $exp = trim(self::$FCM_ACCESS_TOKEN_EXPIRES_AT);    // UNIX timestamp hết hạn[web:86]
        if ($exp !== "" && ctype_digit($exp)) {
            if ((int)$exp <= ($now + 60)) { // Nếu sẽ hết hạn trong 60s, coi như không hợp lệ
                throw new Exception("FCM_ACCESS_TOKEN đã hết hạn hoặc sắp hết hạn (HTTP v1)."); // Lỗi tiếng Việt[web:86]
            }
        }
        return $token; // Trả về token để gắn vào header "Authorization: Bearer ..."
    }

    // Gửi 01 token qua HTTP v1
    protected static function send_v1_to_token($bearerToken, $deviceToken, $notification, $data, $logtitle)
    {
        // Endpoint v1 chuẩn với PROJECT_ID
        $url = "https://fcm.googleapis.com/v1/projects/" . self::$FCM_PROJECT_ID . "/messages:send"; // Endpoint chính thức HTTP v1[web:86]

        // Lưu ý: data trong HTTP v1 phải là map string-string (không object phức tạp)
        $payload = [
            "message" => [
                "token" => (string)$deviceToken, // Token thiết bị mục tiêu[web:86]
                "notification" => [
                    "title" => isset($notification["title"]) ? (string)$notification["title"] : "",
                    "body"  => isset($notification["body"])  ? (string)$notification["body"]  : ""
                ],
                "data" => []
            ]
        ];

        if (is_array($data)) {
            foreach ($data as $k => $v) {
                $payload["message"]["data"][(string)$k] = (string)$v; // Ép tất cả giá trị sang string (yêu cầu v1)[web:86][web:133]
            }
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);                                           // URL HTTP v1[web:86]
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: Bearer " . $bearerToken                                   // Header Bearer bắt buộc[web:86]
        ]);
        curl_setopt($ch, CURLOPT_POST, true);                                          // POST JSON[web:86]
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);                                // Nhận response body về PHP[web:86]
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE)); // Body v1, giữ Unicode[web:86]
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);                                   // Bật verify để an toàn[web:86]
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);                                // Bật verify để an toàn[web:86]

        $out = curl_exec($ch);                                                         // Gọi API v1[web:86]
        $err = curl_error($ch);                                                        // Bắt lỗi cURL (nếu có)[web:86]
        curl_close($ch);

        if ($err) {
            error_log("[THÔNG BÁO] $logtitle: LỖI gửi FCM v1 tới 1 token: ".$err);     // Log lỗi tiếng Việt đầy đủ[web:86]
        } else {
            // Phản hồi thành công thường chứa name: "projects/.../messages/..." hoặc lỗi dạng JSON chi tiết
            error_log("[THÔNG BÁO] $logtitle: Phản hồi FCM v1: ".$out);               // Log response để chẩn đoán[web:86]
        }
    }

    // Gửi cho danh sách người dùng: v1 bắt buộc, lặp từng token (FCM v1 không hỗ trợ registration_ids)
    static function send_event_v1($users, $notification, $data, $logtitle)
    {
        // Kiểm tra cấu hình bắt buộc
        $project = trim(self::$FCM_PROJECT_ID);                 // PROJECT_ID dùng trong endpoint[web:86]
        if ($project === "") {
            error_log("[THÔNG BÁO] $logtitle: LỖI: Thiếu FCM_PROJECT_ID (HTTP v1 bắt buộc)."); // Báo lỗi nếu thiếu[web:86]
            return;
        }

        // Lấy Bearer token từ cấu hình ngoài
        try {
            $bearer = self::getExternalAccessToken();           // Ném Exception nếu không hợp lệ[web:86]
        } catch (\Exception $e) {
            error_log("[THÔNG BÁO] $logtitle: LỖI: Không lấy được access token v1: ".$e->getMessage()); // Log lỗi[web:86]
            return;
        }

        // Thu thập token thiết bị từ danh sách users (lọc theo prefix Android/iOS)
        $prefixes = [ self::$TOKEN_PREFIX_ANDROID, self::$TOKEN_PREFIX_IOS ]; // Prefix token do app client đặt[web:7]
        $deviceTokens = [];

        foreach ($users as $row) {
            if (empty($row['mobile_notification'])) continue;                // Bỏ qua nếu không có token[web:7]
            $raw = $row['mobile_notification'];
            foreach ($prefixes as $pf) {
                if (strpos($raw, $pf) !== false) {                           // Khớp prefix hợp lệ[web:7]
                    $parts = explode(":", $raw, 2);
                    if (sizeof($parts) == 2 && strlen(trim($parts[1])) > 0) {
                        $deviceTokens[] = trim($parts[1]);                   // Lấy token phần sau tiền tố[web:7]
                    }
                    break;
                }
            }
        }

        if (empty($deviceTokens)) {
            error_log("[THÔNG BÁO] $logtitle: Không tìm thấy token hợp lệ để gửi (HTTP v1)."); // Không có thiết bị mục tiêu[web:86]
            return;
        }

        // Gửi từng token (FCM v1 không có registration_ids)
        foreach ($deviceTokens as $tok) {
            self::send_v1_to_token($bearer, $tok, $notification, $data, $logtitle); // Gọi v1 cho từng token[web:86]
        }
    }

    /* ====== Nạp tham số cấu hình từ DB ====== */
    static function getparams()
    {
        // Tải tất cả cặp par_name/par_value từ bảng cấu hình, và gán vào các thuộc tính tĩnh tương ứng
        global $DB; // DB truy vấn glpi_plugin_glpimobilenotification_config[web:5]

        $query = "SELECT par_name, par_value FROM ".self::$CONFIG_TABLE; // Truy vấn bảng cấu hình plugin[web:5]
        $rows  = $DB->queryOrDie($query, $DB->error()); // Lấy danh sách cấu hình hoặc ném lỗi DB[web:5]

        foreach ($rows as $row) {
            $name  = strtoupper($row['par_name']); // Tên key cấu hình sẽ được upper để map vào biến tĩnh[web:5]
            $value = $row['par_value'];            // Giá trị cấu hình dạng chuỗi[web:5]
            self::$$name = $value;                 // Gán động dựa trên tên biến tĩnh trùng tên key[web:5]
        }

        // Bật ghi log vào file cấu hình nếu write_log=1
        if (self::$WRITE_LOG == "1") {
            self::$LOG_FILE_OLD  = ini_get("error_log");  // Lưu lại error_log hiện tại để khôi phục sau[web:7]
            self::$WRITE_LOG_OLD = ini_get("log_errors"); // Lưu lại trạng thái log_errors hiện tại[web:7]
            ini_set("log_errors", TRUE);                  // Bật chế độ ghi log lỗi[web:7]
            ini_set('error_log', self::$LOG_FILE);        // Ghi log plugin vào file chỉ định (vd smartdesk_mobile.log)[web:5]
            error_log("[THIẾT LẬP] Đã chuyển hướng log plugin sang: ".self::$LOG_FILE); // Xác nhận hướng log tiếng Việt[web:148]
        }

        // Có thể log nhanh cấu hình quan trọng (ẩn giá trị nhạy cảm) để debug:
        // error_log("[THIẾT LẬP] FCM v1: PROJECT_ID=".self::$FCM_PROJECT_ID."; TOKEN_SET=".(!empty(self::$FCM_ACCESS_TOKEN) ? "YES":"NO"));
    }

    /* ====== Lấy danh sách entity (bao gồm cha) ====== */
    static function getEntities($ticketentity)
    {
        // Trả về danh sách id entity bao gồm entity hiện tại và tất cả entity cha, phân tách dấu phẩy để dùng trong SQL IN()[web:7]
        global $DB;

        $entities = (string)(int)$ticketentity; // Bắt đầu từ entity hiện tại[web:7]

        $sql = "SELECT completename, name FROM glpi_entities WHERE id=".(int)$ticketentity; // Lấy completename để tách chuỗi cha-con[web:7]
        foreach ($DB->request($sql) as $row) {
            if (!empty($row['completename'])) {
                // completename dạng "Parent > Child > Sub", tách thành mảng tên để truy vấn id tất cả cấp
                $names = "'".str_replace(" > ", "','", $row['completename'])."'"; // Ghép thành danh sách tên entity[web:7]
                $q2 = "SELECT id FROM glpi_entities WHERE name IN ($names)";      // Truy ID mọi entity theo tên[web:7]
                foreach ($DB->request($q2) as $r2) {
                    $entities .= ",".$r2['id']; // Ghép thêm id vào chuỗi kết quả[web:7]
                }
            }
        }
        return $entities; // Ví dụ: "5,2,1" nghĩa là entity hiện tại + cha + ông[web:7]
    }
}
