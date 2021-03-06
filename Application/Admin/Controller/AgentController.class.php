<?php
/**
 * Created by PhpStorm.
 * agent: Cherish
 * Date: 2016/12/22
 * Time: 9:46
 */
namespace Admin\Controller;
use Common\Controller\BaseController;
use Think\Page;

class AgentController extends BaseController
{
    public function agentsGet() {

        $admin_agent = $this->mongo_db->admin_agent;
        $admin_role = $this->mongo_db->admin_role;

        $agent_type = C('SYSTEM.AGENT_TYPE');
        $stock_type = C('SYSTEM.STOCK_TYPE');
        $this->_result['data']['agent_type'] = $agent_type;
        $this->assign("stock_type", $stock_type);

        if (I('get._id')) {
            $search['_id'] = new \MongoId(I('get._id', null));
            $option = array('password' => 0);
            $query = $admin_agent->findOne($search, $option);
            $this->_result['data']['agents'] = $query;
            if (I('get.tab') == 'card') {//充卡页面
                $stock_amount_type = C('SYSTEM.STOCK_AMOUNT_TYPE');
                $query['type_name'] = $stock_type[$query['type']];
                $query['agent_type_name'] = $agent_type[$query['level']];
                $stock_amount = $query['stock_amount'];
                foreach ($stock_amount as $key => $value) {
                    $stock_amount[$key] = array(
                        'name' => $stock_type[$key],
                        'amount' => $value
                    );
                }
                $this->assign("agents", $query);
                $this->assign("stock_amount", $stock_amount);
                $this->assign("stock_amount_type", $stock_amount_type);
                $html = $this->fetch("Agent:card");
                $this->_result['data']['html'] = $html;
                $this->response($this->_result);
            }
        } else {
            $search = array();
            $search['username'] = I('get.username', null);
            $search['name'] = I('get.name', null);
            $limit = intval(I('get.limit', C('PAGE_NUM')));
            $skip = (intval(I('get.p', 1)) - 1) * $limit;
            filter_array_element($search);
            filter_array_element($option);

            $cursor = $admin_agent->find($search)->sort(array('date' => -1))->limit($limit)->skip($skip);
            $result = array();
            foreach ($cursor as $item) {
                $role = $admin_role->findOne(array('_id' => $item['role_id']),array('name'=>1));
                $item['role_name'] = $role['name'];
                $item['type_name'] = $agent_type[$item['type']];
                $item['date'] = date('Y-m-d H:i:s', $item['date']);
                array_push($result, $item);
            }

            $count = $admin_agent->count($search);
            $page = new Page($count, C('PAGE_NUM'));
            $page = $page->show();

            //role list
            $module_list = C('SYSTEM.MODULE_LIST');
            $role_cursor = $admin_role->find(array("module_name"=>$module_list['Agent']));
            $roles = iterator_to_array($role_cursor);

            $this->assign("page", $page);
            $this->assign("roles", $roles);
            $this->assign("agents", $result);
            $this->assign("agent_type", $agent_type);
            $this->_result['data']['html'] = $this->fetch("Agent:index");

            $this->_result['data']['count'] = $count;
            $this->_result['data']['page'] = $page;
            $this->_result['data']['agents'] = $result;
            $this->_result['data']['roles'] = $roles;
        }
        $this->response($this->_result);
    }

    public function agentsPut() {
        $admin_agent = $this->mongo_db->admin_agent;
        $admin_user = $this->mongo_db->admin_user;
        $admin_stock_grant_record = $this->mongo_db->admin_stock_grant_record;

        $search['_id'] = new \MongoId(I('put._id'));
        $data['name'] = I('put.name', null, check_empty_string);
        $data['password'] = I('put.password', null);
        $data['repeat_password'] = I('put.repeat_password', null);
        $data['type'] = intval(I('put.type'));
        if (I('put.status') !== '') {
            $data['status'] = intval(I('put.status'));
        }
        $data['role_id'] = I('put.role_id', null);
        merge_params_error($data['name'], 'name', '昵称不能为空', $this->_result['error'], false);
        merge_params_error($data['password'], 'password', '密码不能为空', $this->_result['error'], false);
        merge_params_error($data['repeat_password'], 'repeat_password', '密码不能为空', $this->_result['error'], false);
        //检查参数
        if ($this->_result['error']) {
            $error = array_shift($this->_result['error']);
            $error = array_values($error);
            $this->response($this->_result, 'json', 400, $error[0]);
        }

        if ($data['password'] && $data['repeat_password']) {
            if ($data['password'] != $data['repeat_password']) {
                $this->response($this->_result, 'json', 400, '两次输入的密码不一致');
            }

            if (checkTextLength6($data['password']) || checkTextLength6($data['repeat_password'])) {
                $this->response($this->_result, 'json', 400, '密码至少6个字符');
            }
            unset($data['repeat_password']);
            $data['password'] = md5($data['password']);
        }
        if (isset($data['password']) && !$data['password']) {
            unset($data['password']);
        }
        if (isset($data['repeat_password']) && !$data['repeat_password']) {
            unset($data['repeat_password']);
        }

        //充卡
        $amount = intval(I('put.amount'));

        if (!check_positive_integer($amount)) {
            $this->response($this->_result, 'json', 400, '房卡数量必须为正整数');
        } else {
            //库存是否充足
            $user = $admin_user->findOne(array("_id" => $_SESSION[MODULE_NAME.'_admin']['_id']));
            if($user['stock_amount'][$data['type']] < $amount) {
                $this->response($this->_result, 'json', 400, '房卡库存不足，请前往"库存管理"申请足量房卡');
            }
            $update['$inc'] = array("stock_amount.{$data['type']}" => $amount);
        }

        if ($data['role_id']) {
            $data['role_id'] = new \MongoId($data['role_id']);
        }
        filter_array_element($data);

        $update['$set'] = $data;
        if ($agent = $admin_agent->findAndModify($search,$update)) {
            if($update['$inc']) {//给代理充卡后要扣除管理员相应的库存卡数量
                $admin_user->update(array("_id" => $_SESSION[MODULE_NAME.'_admin']['_id']),
                    array('$inc' => array("stock_amount.{$data['type']}" => -$amount))
                );
                //充卡记录
                $admin_stock_grant_record->insert(
                    array(
                        'from_user' => $_SESSION[MODULE_NAME.'_admin']['username'],
                        'to_user' => $agent['username'],
                        'type' => $data['type'],
                        'amount' => $amount,
                        'date' => time(),
                    )
                );
            }
            $this->response($this->_result, 'json', 201, '保存成功');
        } else {
            $this->_result['data']['param'] = $data;
            $this->response($this->_result, 'json', 400, '保存失败');
        }

    }

    public function agentsPost() {
        $admin_agent = $this->mongo_db->admin_agent;
        $data['username'] = I('post.username', null, check_empty_string);
        $data['name'] = I('post.name', null, check_empty_string);
        $data['password'] = I('post.password', null, check_empty_string);
        $data['repeat_password'] = I('post.repeat_password', null, check_empty_string);
        $data['type'] = intval(I('post.type'));
        $data['level'] = 1; //一级代理
        $data['status'] = intval(I('post.status'));
        $data['role_id'] = I('post.role_id');
        $data['pid'] = 0;
        $data['date'] = time();
        merge_params_error($data['username'], 'username', '用户名不能为空', $this->_result['error']);
        merge_params_error($data['name'], 'name', '名字不能为空', $this->_result['error']);
        merge_params_error($data['password'], 'password', '密码不能为空', $this->_result['error']);
        merge_params_error($data['repeat_password'], 'repeat_password', '密码不能为空', $this->_result['error']);

        //检查参数
        if ($this->_result['error']) {
            $error = array_shift($this->_result['error']);
            $error = array_values($error);
            $this->response($this->_result, 'json', 400, $error[0]);
        }

        if (checkTextLength6($data['username'])) {
            $this->response($this->_result, 'json', 400, '用户名至少6个字符');
        }

        if ($data['password'] != $data['repeat_password']) {
            $this->response($this->_result, 'json', 400, '两次输入的密码不一致');
        }

        if (checkTextLength6($data['password'])||checkTextLength6($data['repeat_password'])) {
            $this->response($this->_result, 'json', 400, '密码至少6个字符');
        }

        if (findRecord('username', $data['username'], $admin_agent)) {
            $this->response($this->_result, 'json', 400, '用户名已经存在');
        }

        filter_array_element($data);

        $data['role_id'] = new \MongoId($data['role_id']);
        $data['password'] = md5($data['password']);
        unset($data['repeat_password']);

        if ($admin_agent->insert($data)) {
            $this->_result['data']['url'] = U(MODULE_NAME.'/agent/agents');
            $this->response($this->_result, 'json', 201, '新建成功');
        } else {
            $this->response($this->_result, 'json', 400, '新建失败');
        }
    }

    public function agentsDelete() {
        $search['_id'] = new \MongoId(I('delete._id'));
        $admin_agent = $this->mongo_db->admin_agent;
        if ($admin_agent->remove($search)) {
            $this->response($this->_result, 'json', 204, '删除成功');
        } else {
            $this->response($this->_result, 'json', 400, '删除失败');
        }
    }

    //给代理发放房卡记录
    public function recordGet() {
        $search = array();
        $search['to_user'] = I('get.to_user', null);
        $stock_type = C('SYSTEM.STOCK_TYPE');
        $admin_stock_grant_record = $this->mongo_db->admin_stock_grant_record;
        $admin_agent = $this->mongo_db->admin_agent;

        //$search['from_user'] = $_SESSION[MODULE_NAME.'_admin']['username'];
        $limit = intval(I('get.limit', C('PAGE_NUM')));
        $skip = (intval(I('get.p', 1)) - 1) * $limit;
        $option = array();
        filter_array_element($search);
        filter_array_element($option);
        $cursor = $admin_stock_grant_record->find($search, $option)->sort(array('date' => 1))->skip($skip)->limit($limit);
        $result = array();
        $agent_type = C('SYSTEM.AGENT_TYPE');
        foreach ($cursor as $item) {
            $item['date'] = date("Y-m-d H:i:s");
            $item['type_name'] = $stock_type[$item['type']];
            $agent = $admin_agent->findOne(array('username' => $item['to_user']));
            $item['name'] = $agent['name'];
            $item['agent_type'] = $agent_type[$item['type']];
            $item['wechat'] = $agent['wechat'];
            array_push($result, $item);
        }

        $count = $admin_stock_grant_record->count($search);
        $page = new Page($count, C('PAGE_NUM'));
        $page = $page->show();

        $this->assign("page", $page);
        $this->assign("record", $result);
        $html = $this->fetch("Agent:record");
        $this->_result['data']['html'] = $html;
        $this->_result['data']['record'] = $result;
        $this->response($this->_result);
    }
}