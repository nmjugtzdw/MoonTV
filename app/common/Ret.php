<?php

namespace app\common;

use think\Response;

class Ret
{
    /**
     * 成功响应
     * @param mixed $data 数据
     * @param string $msg 消息
     * @param int $code 状态码
     * @return Response
     */
    public static function success($data = [], $msg = 'ok', $code = 200)
    {
        return json([
            'code' => $code,
            'msg'  => $msg,
            'data' => $data,
        ]);
    }

    /**
     * 错误响应
     * @param string $msg 错误消息
     * @param int $code 错误码
     * @param mixed $data 附加数据
     * @return Response
     */
    public static function error($msg = 'error', $code = 400, $data = [])
    {
        return json([
            'code' => $code,
            'msg'  => $msg,
            'data' => $data,
        ]);
    }

    /**
     * 构建列表响应
     * @param array $list 列表数据
     * @param int $total 总数
     * @param int $page 当前页
     * @param int $limit 每页数量
     * @return Response
     */
    public static function list($list, $total, $page = 1, $limit = 20)
    {
        return self::success([
            'list'  => $list,
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
        ]);
    }
}