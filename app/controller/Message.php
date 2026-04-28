<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\model\Message as MessageModel;
use app\model\User as UserModel;
use think\facade\Request;
use think\facade\Session;
use think\facade\View;

class Message extends BaseController
{
    public function index()
    {
        $userId = Session::get('user_id');
        if (!$userId) {
            return redirect('/user/login');
        }

        // 收到的
        $inbox = MessageModel::where('receiver_id', $userId)
            ->order('create_time', 'desc')
            ->limit(50)
            ->select();

        // 我发送的
        $sent = MessageModel::where('sender_id', $userId)
            ->order('create_time', 'desc')
            ->limit(50)
            ->select();

        // 预加载用户昵称
        $userIds = [];
        foreach ($inbox as $m) {
            if ($m->sender_id > 0) {
                $userIds[] = (int)$m->sender_id;
            }
        }
        foreach ($sent as $m) {
            if ($m->receiver_id > 0) {
                $userIds[] = (int)$m->receiver_id;
            }
        }
        $userIds = array_unique($userIds);

        $userMap = [];
        if (!empty($userIds)) {
            $users = UserModel::whereIn('id', $userIds)->select();
            foreach ($users as $u) {
                $userMap[(int)$u->id] = $u;
            }
        }

        View::assign([
            'inbox'   => $inbox,
            'sent'    => $sent,
            'userMap' => $userMap,
        ]);

        return View::fetch('message/index');
    }

    // 站内信详情（顺便标记已读）
    public function detail()
    {
        $userId = Session::get('user_id');
        if (!$userId) {
            return redirect('/user/login');
        }

        $id = (int)Request::get('id', 0);
        /** @var MessageModel|null $msg */
        $msg = MessageModel::find($id);
        if (
            !$msg
            || ((int)$msg->receiver_id !== (int)$userId
                && (int)$msg->sender_id !== (int)$userId)
        ) {
            // 自己发的也可以看详情，所以这里放宽为 sender 或 receiver
            return '消息不存在或无权查看';
        }

        // 标记已读：只对我是接收人时生效
        if ((int)$msg->receiver_id === (int)$userId && (int)$msg->is_read === 0) {
            $msg->is_read = 1;
            $msg->save();
        }

        // 系统消息：不需要对话，只要把 msg、本用户 ID 传给模板即可
        if ($msg->type === 'system') {
            View::assign('msg', $msg);
            View::assign('currentUserId', $userId);
            // thread / userMap / convId 在模板里不会用到系统消息分支，可以不给
            return View::fetch('message/detail');
        }

        // ===== 以下是普通用户之间的对话 =====

        $sender = $msg->sender_id > 0 ? UserModel::find($msg->sender_id) : null;

        // 会话 ID：没有就用自己 id（兼容旧数据）
        $convId = $msg->conversation_id ?: $msg->id;

        // 取出本会话下所有相关消息（来回对话）
        $thread = MessageModel::where('conversation_id', $convId)
            ->order('create_time', 'asc')
            ->select();

        // 预加载所有参与者昵称
        $userIds = [];
        foreach ($thread as $m) {
            if ($m->sender_id > 0) {
                $userIds[] = (int)$m->sender_id;
            }
            if ($m->receiver_id > 0) {
                $userIds[] = (int)$m->receiver_id;
            }
        }
        $userIds = array_unique($userIds);

        $userMap = [];
        if (!empty($userIds)) {
            $users = UserModel::whereIn('id', $userIds)->select();
            foreach ($users as $u) {
                $userMap[(int)$u->id] = $u;
            }
        }

        View::assign('msg', $msg);
        View::assign('sender', $sender);
        View::assign('thread', $thread);
        View::assign('userMap', $userMap);
        View::assign('convId', $convId);
        View::assign('currentUserId', $userId);

        return View::fetch('message/detail');
    }

    // 发消息页面（输入对方昵称）
    public function compose()
    {
        $userId = Session::get('user_id');
        if (!$userId) {
            return redirect('/user/login');
        }

        return View::fetch('message/compose');
    }

    // 发送消息（根据昵称找对方）
    public function send()
    {
        $userId = Session::get('user_id');
        if (!$userId) {
            return redirect('/user/login');
        }

        if (!Request::isPost()) {
            return redirect('/message/compose');
        }

        $toNickname     = trim((string)Request::post('to_nickname', ''));
        $content        = trim((string)Request::post('content', ''));
        $title          = trim((string)Request::post('title', ''));
        $backUrl        = (string)Request::post('back', ''); // 可选：从详情页回来用
        $conversationId = (int)Request::post('conversation_id', 0);

        if ($toNickname === '' || $content === '') {
            return '收件人昵称和内容不能为空';
        }

        // 根据昵称查用户（如果昵称不唯一，也可以改成精确用户名）
        $receiver = UserModel::where('nickname', $toNickname)->find();
        if (!$receiver) {
            return '收件人不存在，请检查昵称';
        }

        if ((int)$receiver->id === (int)$userId) {
            return '不能给自己发送消息';
        }

        $msg = MessageModel::create([
            'sender_id'       => $userId,
            'receiver_id'     => (int)$receiver->id,
            'type'            => 'user', // 用户间消息
            'title'           => $title,
            'content'         => $content,
            'is_read'         => 0,
            'create_time'     => date('Y-m-d H:i:s'),
            'conversation_id' => $conversationId ?: null, // 先填传进来的，如果为空后面再补
        ]);

        // 如果没有传 conversation_id，说明是新会话：用自己 id 作为会话 id
        if (empty($conversationId)) {
            $msg->conversation_id = $msg->id;
            $msg->save();
        }

        if ($backUrl !== '') {
            return redirect($backUrl);
        }
        return redirect('/message/index');
    }
}
