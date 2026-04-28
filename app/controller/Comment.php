<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\model\Comment as CommentModel;
use app\model\HelpArticle as HelpModel;
use app\model\Project as ProjectModel;
use app\model\News as NewsModel;
use think\facade\Request;
use think\facade\Session;

class Comment extends BaseController
{
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

    // 提交评论 / 回复
    public function add()
    {
        $userId = Session::get('user_id');
        if (!$userId) {
            return $this->alertAndBack('请先登录后再发表评论', '/user/login');
        }

        if (!Request::isPost()) {
            return $this->alertAndBack('不支持的请求方式');
        }

        $type     = (string)Request::post('type');      // help/project/news
        $targetId = (int)Request::post('target_id');
        $parentId = (int)Request::post('parent_id', 0); // 0 顶级评论，其它为回复
        $content  = trim((string)Request::post('content'));

        if ($content === '') {
            return $this->alertAndBack('评论内容不能为空');
        }

        if (mb_strlen($content, 'UTF-8') > 500) {
            return $this->alertAndBack('评论内容不能超过500字');
        }

        // 检查目标存在，并确定重定向地址
        $target      = null;
        $redirectUrl = '/';
        if ($type === 'help') {
            $target      = HelpModel::find($targetId);
            $redirectUrl = '/help/detail?id=' . $targetId;
        } elseif ($type === 'project') {
            $target      = ProjectModel::find($targetId);
            $redirectUrl = '/project/detail?id=' . $targetId;
        } elseif ($type === 'news') {
            $target      = NewsModel::find($targetId);
            $redirectUrl = '/news/detail?id=' . $targetId;
        } else {
            return $this->alertAndBack('未知的评论类型');
        }

        if (!$target) {
            return $this->alertAndBack('评论的对象不存在', '/');
        }

        // 若是回复，检查父评论是否存在且同属同一目标
        $parent = null;
        if ($parentId > 0) {
            $parent = CommentModel::where('id', $parentId)
                ->where('type', $type)
                ->where('target_id', $targetId)
                ->find();
            if (!$parent) {
                return $this->alertAndBack('要回复的评论不存在', $redirectUrl);
            }
        }

        // 写入评论 / 回复
        $comment = CommentModel::create([
            'user_id'   => $userId,
            'type'      => $type,
            'target_id' => $targetId,
            'parent_id' => $parentId,
            'content'   => $content,
        ]);

        if (!$comment) {
            return $this->alertAndBack('评论失败，请稍后重试', $redirectUrl);
        }

        // ================== 发送站内信通知 ==================

        // 1）顶级评论：通知内容发布者
        if ($parentId === 0) {
            if ($type === 'project') {
                /** @var ProjectModel $target */
                $ownerId = (int)$target->publisher_id;
                if ($ownerId > 0 && $ownerId !== (int)$userId) {
                    $title   = '你的公益项目有新评论';
                    $contentMsg = '你的项目《' . $target->title . '》收到一条新的评论。';
                    send_system_message($ownerId, $title, $contentMsg);
                }
            } elseif ($type === 'help') {
                /** @var HelpModel $target */
                $ownerId = (int)$target->author_id;
                if ($ownerId > 0 && $ownerId !== (int)$userId) {
                    $title   = '你的求助文章有新评论';
                    $contentMsg = '你的求助《' . $target->title . '》收到一条新的评论。';
                    send_system_message($ownerId, $title, $contentMsg);
                }
            } elseif ($type === 'news') {
                /** @var NewsModel $target */
                $ownerId = (int)$target->author_id;
                if ($ownerId > 0 && $ownerId !== (int)$userId) {
                    $title   = '你的新闻案例有新评论';
                    $contentMsg = '你的新闻《' . $target->title . '》收到一条新的评论。';
                    send_system_message($ownerId, $title, $contentMsg);
                }
            }
        }

        // 2）回复评论：通知被回复的人
        if ($parentId > 0 && $parent) {
            $parentUserId = (int)$parent->user_id;
            if ($parentUserId > 0 && $parentUserId !== (int)$userId) {
                $map = [
                    'project' => '公益项目',
                    'help'    => '求助文章',
                    'news'    => '新闻案例',
                ];
                $typeName = $map[$type] ?? '内容';

                $title   = '你的评论有了新回复';
                $contentMsg = '你在「' . $typeName . '」下的评论收到一条新的回复。';
                send_system_message($parentUserId, $title, $contentMsg);
            }
        }

        // =====================================================

        // 成功：重定向刷新详情页
        return redirect($redirectUrl);
    }
}
