<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\model\Feedback as FeedbackModel;
use think\facade\Request;
use think\facade\Session;

class Feedback extends BaseController
{
    // 通用弹窗
    protected function alertAndBack(string $message, string $redirectUrl = '')
    {
        $js = $redirectUrl
            ? "window.location.href = '{$redirectUrl}';"
            : "history.back();";

        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>提示</title>
</head>
<body>
<script>
    alert('{$message}');
    {$js}
</script>
</body>
</html>
HTML;
    }

    // 提交反馈
    public function submit()
    {
        if (!Request::isPost()) {
            return $this->alertAndBack('不支持的请求方式');
        }

        $userId  = Session::get('user_id');
        $name    = trim((string)Request::post('name'));
        $contact = trim((string)Request::post('contact'));
        $message = trim((string)Request::post('message'));

        if (empty($message)) {
            return $this->alertAndBack('反馈内容不能为空');
        }

        if (mb_strlen($message, 'UTF-8') > 1000) {
            return $this->alertAndBack('反馈内容不能超过1000字');
        }

        $ok = FeedbackModel::create([
            'user_id' => $userId ?: null,
            'name'    => $name ?: null,
            'contact' => $contact ?: null,
            'message' => $message,
            'status'  => 'new',
        ]);

        if ($ok) {
            // 提交成功后跳回联系我们页面
            return $this->alertAndBack('感谢您的反馈，我们会尽快处理！', '/contact/index');
        } else {
            return $this->alertAndBack('反馈提交失败，请稍后重试');
        }
    }
}
