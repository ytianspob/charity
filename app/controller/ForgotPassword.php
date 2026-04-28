<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\model\User as UserModel;
use think\facade\Request;
use think\facade\View;
use think\facade\Session;

class ForgotPassword extends BaseController
{
    // 第一步：输入用户名
    public function step1()
    {
        return View::fetch('auth/forgot_step1');
    }

    // 处理第一步：根据用户名显示密保问题
    public function checkUser()
    {
        if (!Request::isPost()) {
            return redirect('/forgot/step1');
        }

        $username = trim((string)Request::post('username', ''));
        if ($username === '') {
            Session::flash('auth_msg', '请输入用户名');
            Session::flash('auth_status', 'error');
            return redirect('/forgot/step1');
        }

        /** @var UserModel|null $user */
        $user = UserModel::where('username', $username)->find();
        if (!$user) {
            Session::flash('auth_msg', '用户不存在');
            Session::flash('auth_status', 'error');
            return redirect('/forgot/step1');
        }

        if (empty($user->security_question) || empty($user->security_answer)) {
            Session::flash('auth_msg', '该用户未设置密保，无法通过密保找回密码，请联系管理员。');
            Session::flash('auth_status', 'error');
            return redirect('/forgot/step1');
        }

        // 进入第二步页面，传递用户 ID 和密保问题
        View::assign([
            'user_id'           => $user->id,
            'username'          => $user->username,
            'security_question' => $user->security_question,
        ]);

        return View::fetch('auth/forgot_step2');
    }

    // 第二步：校验密保答案并重置密码
    public function reset()
    {
        if (!Request::isPost()) {
            return redirect('/forgot/step1');
        }

        $userId  = (int)Request::post('user_id', 0);
        $answer  = trim((string)Request::post('security_answer', ''));
        $newPwd  = (string)Request::post('new_password', '');
        $confirm = (string)Request::post('confirm_password', '');

        /** @var UserModel|null $user */
        $user = UserModel::find($userId);
        if (!$user) {
            Session::flash('auth_msg', '用户不存在');
            Session::flash('auth_status', 'error');
            return redirect('/forgot/step1');
        }

        if ($answer === '' || $newPwd === '' || $confirm === '') {
            Session::flash('auth_msg', '密保答案和新密码不能为空');
            Session::flash('auth_status', 'error');
            return redirect('/forgot/step2?user_id=' . $userId);
        }

        if ($newPwd !== $confirm) {
            Session::flash('auth_msg', '两次输入的新密码不一致');
            Session::flash('auth_status', 'error');
            return redirect('/forgot/step2?user_id=' . $userId);
        }

        // 校验密保答案（按需要可改成忽略大小写）
        if ($answer !== (string)$user->security_answer) {
            Session::flash('auth_msg', '密保答案不正确');
            Session::flash('auth_status', 'error');
            return redirect('/forgot/step2?user_id=' . $userId);
        }

        // 重置密码
        $user->password = password_hash($newPwd, PASSWORD_DEFAULT);
        if (!$user->save()) {
            Session::flash('auth_msg', '密码重置失败，请稍后重试');
            Session::flash('auth_status', 'error');
            return redirect('/forgot/step2?user_id=' . $userId);
        }

        // 成功：跳转到登录页，并提示“请用新密码登录”
        Session::flash('auth_msg', '密码已重置，请使用新密码登录');
        Session::flash('auth_status', 'success');
        return redirect('/user/login');
    }
}
