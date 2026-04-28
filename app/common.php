<?php
declare (strict_types = 1);

use app\model\Message;

/**
 * 发送系统站内信
 */
function send_system_message(int $receiverId, string $title, string $content): void
{
    if ($receiverId <= 0) {
        return;
    }

    Message::create([
        'sender_id'   => 0,              // 0 代表系统
        'receiver_id' => $receiverId,
        'type'        => 'system',       // 显式标记为系统消息
        'title'       => $title,
        'content'     => $content,
        'is_read'     => 0,
        'create_time' => date('Y-m-d H:i:s'),
    ]);
}