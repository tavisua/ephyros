<?php

namespace App\Http\Controllers;

use App\Actions;

use Request;
use DateTime;
use App\User;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\TranslateController;


class ActionsController extends Controller
{
	public function showProfile($type){
		dd($type);
	}
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index($type)
    {
    	if(Auth::user()) {

    		//Устанавливаю значение типа в соответсвии с кодом базы данных
		    switch ($type){
			    case 'current':{
			    	$type = 'AC_CURRENT';
			    }break;
			    case 'global':{
			    	$type = 'AC_GLOBAL';
			    }break;
		    }

		    $time = time();

    		//Определяю ИД типа задачи
		    $idAction = DB::table('llx_c_actioncomm')
		      ->select('id')
			    ->where('code', '=', $type)
			    ->where('active', '=', 1)
			    ->get();

		    //Загружаю ИД задач
		    $listIdBuilder = DB::table('llx_actioncomm')
			  ->select('id', 'new', 'fk_user_author', 'fk_user_action', 'fk_groupoftask')
			    ->where('fk_action', '=', $idAction[0]->id)
			    ->where('active', '=', 1);

			//Проверяю установлен ли фильтр по степени выполнения задач
		    if(Request::input('filterdatas')){
			    $status = substr(Request::input('filterdatas'), strpos(Request::input('filterdatas'), ':"')+2);
			    $status = substr($status,0, strlen($status)-2);
			    switch($status) {
				    case 'ActionNotRunning': {
					    $listIdBuilder ->where('percent', '=', -1);
				    }
					    break;
				    case 'ActionRunningNotStarted': {
					    $listIdBuilder ->where('percent', '=', 0);
				    }
					    break;
				    case 'ActionRunningShort': {
					    $listIdBuilder ->whereBetween('percent', [1,99]);
				    }
					    break;
				    case 'ActionDoneShort': {
					    $listIdBuilder ->where('percent', '=', 100);

				    }break;
			    }
		    }else{//Если нет, загружаю только задачи, статус которых не выполнен или отменен
		    	$listIdBuilder->whereNotIn('percent', [100, -100]);
		    }

		    //Проверяю установлены ли фильтра по списку задач
		    if(Request::input('filterdatas')) {
			    $filter = (array)json_decode(Request::input('filterdatas'));
			    foreach(array_keys($filter) as $key){
				    if(in_array($key, array('execdate','prepareddate','daterecord','confirmdate'))) {//Фільтр дат
					    switch ($key) {
						    case 'execdate': {
							    $fieldName = 'datep';
						    }
							    break;
						    case 'prepareddate': {
							    $fieldName = 'datepreperform';
						    }
							    break;
						    case 'daterecord': {
							    $fieldName = 'datec';
						    }
							    break;
						    case 'confirmdate': {
							    $fieldName = 'dateconfirm';
						    }
							    break;
					    }
					    $listIdBuilder->whereIn("date($fieldName)", $filter[$key]);
				    }

			    }

		    }

		    $listID = $listIdBuilder->get();
			$actionsArrayID = [];
			foreach ($listID as $item){
				$actionsArrayID[] = $item->id;
			}
		    $taskID = [];
		    $users = [];
		    foreach ($listID as $obj){
			    //Проверяю если авторизированных пользователь входит в список исполнителей
			    //добавляю ИД задачи в перечень задач

			    if(in_array(Auth::user()->rowid, array_keys($this->getExecuters($obj->id))) || Auth::user()->rowid == $obj->fk_user_author){
				    $taskID[]               = $obj->id;
				    $taskAuthor[ $obj->id ] = empty($obj->fk_user_author)?$obj->fk_user_action:$obj->fk_user_author;
				    if(!in_array($obj->fk_user_author, $users))
					    $users[] = $obj->fk_user_author;
				    if ( $obj->fk_groupoftask == 10 ) {

					    //Получаю цепочку связанных задач
					    $chainaction = $this->getJoinActions( $obj->id );

					    //Список контрагентов, связанных с задачами
					    $societeID = DB::table( 'llx_actioncomm' )
					                   ->select( 'fk_soc' )
					                   ->whereIn( 'id', $chainaction )
					                   ->where( 'active', '=', 1 )
					                   ->whereNotNull( 'fk_soc' )->get();

					    foreach ( $societeID as $item ) {
						    $taskSociete[ $obj->id ] = $item->fk_soc;
					    }

				    }
			    }
		    }
		    //завантажую ІД пов'язаних з задачами користувачів
			$usersID = DB::table('llx_actioncomm_resources')
				->select('fk_actioncomm','fk_element')
				->whereIn('fk_actioncomm', $taskID)->get();
		    $assignedUser = [];

		    foreach ($usersID as $key=>$item){
		    	if(!isset($assignedUser[$item->fk_actioncomm]))
			        $assignedUser[$item->fk_actioncomm] = $item->fk_element;
		    	else if(!in_array($item->fk_element, $assignedUser[$item->fk_actioncomm]))
				    $assignedUser[$item->fk_actioncomm][]=$item->fk_element;
		    	if(!in_array($item->fk_element, $users))
				    $users[] = $item->fk_element;
		    }
		    foreach ($assignedUser as $key=>$item){

		    	if(count($assignedUser[$key])>1)
			        $assignedUser[$key] = implode(',', $assignedUser[$key]);
		    	else
				    $assignedUser[$key] = $assignedUser[$key];
		    }
		    //Загружаю информацию о пользователях
		    $userInfo = new User();
		    foreach ($users as $rowid){
		    	$users[$rowid] = $userInfo->fetch($rowid);
		    }
		    //Получаю информацию о последних действиях с контрагентами
		    if(count($taskID)>0) {
				$lastSocAction = DB::table('llx_societe_action')
					->groupBy("llx_societe_action.action_id")
					->select(DB::raw("llx_societe_action.action_id as rowid, max(dtChange) dtChange"))
					->whereIn('action_id', $taskID)
					->where('active', '=', 1)->get();


			    foreach ($lastSocAction as $row){
				    if (!isset($lastaction[$row->rowid])) {
					    $date = new DateTime($row->dtChange);
					    $lastaction[$row->rowid] = $date->format('d.m.y');
				    }
			    }
		    }

		    $translate = (new TranslateController())->translate(); //Массив перевода на украинский
		    $selperiod = (new TranslateController())->selperiod(); //Массив перевода периодов


		    //Загружаю задачи
	        $actionsList = DB::table('llx_actioncomm')
			    ->select('id', 'note', 'new', 'confirmdoc', 'entity', 'datec', 'fk_user_author', 'datep2', 'datelastaction', 'planed_cost', 'fact_cost', 'motivator', 'demotivator', 'datefutureaction', 'datep', 'dateconfirm', 'datepreperform', 'fk_order_id', 'period', 'percent', 'llx_c_groupoftask.name as groupoftask', 'fk_groupoftask')
			    ->leftJoin('llx_c_groupoftask', 'llx_c_groupoftask.rowid', '=', 'fk_groupoftask')
			    ->whereIn('id', $taskID)
		        ->orderBy('datep', 'desc')->paginate(25);
		    return view( 'actions.dashboard' )->with(compact('actionsList', 'users', 'assignedUser', 'taskAuthor', 'translate','selperiod'));
	    }else
	    	return redirect('login');
    }
	function reSetPasswordCrypt(){
		$users = DB::table('llx_user')
		           ->select('rowid','pass')
		           ->where('active','=',1)->get();
		foreach ($users as $key=>$pass){
			DB::connection("mysql")->update('update llx_user set `pass_crypted` = "'.bcrypt($pass->pass).'" where rowid = '.$pass->rowid);
		}
	}

    /*Возвращает список пользователей, задействованных в задаче
     * @param int
     * @return array*/
	function getExecuters($action_id){
		$executer = DB::table('llx_actioncomm_resources')
			->select('fk_element')
			->where('fk_actioncomm', '=', $action_id)->get();
		$out = '{ ';
		$user = DB::table('llx_user')
		            ->select('rowid','lastname','firstname');
		if (!$executer) {
			$userInfo = $user
						->join('llx_actioncomm', 'llx_user.rowid', '=', 'llx_actioncomm.fk_user_author')
						->where('llx_actioncomm.id', '=', $action_id)->first();
			if($userInfo)
				$out .= '"'.$userInfo->rowid . '" : "' . $userInfo->lastname . ' ' . mb_substr($userInfo->firstname, 0, 1, 'UTF-8').'"' ;
		}else {
			foreach ($executer as $key=>$item){
				$result = $user
							->where('llx_user.rowid', '=', $item->fk_element)->first();
				if(strlen($out)>2)
					$out.=',';
				$out .= '"'.$result->rowid . '" : "' . $result->lastname . ' ' . mb_substr($result->firstname, 0, 1, 'UTF-8').'"' ;
			}
		}
		$out .= ' }';
		$out = json_decode($out, true);
		return $out;
	}

    /*Возвращает список родительских задач
	 * @param int ID задачи
	 * @param string название елемента в массиве
	 * @return array*/
	function GetNextAction($actions_id, $name){
		foreach ($actions_id as $item =>$value){
			if(empty($value))
				unset($actions_id[$item]);
		}
		if(empty($actions_id))
			return array(0);
		$actions = DB::table('llx_actioncomm')
			->select('id', 'datep')
			->whereIn('fk_parent', $actions_id)
			->where('active', '=', 1)->get();

		$out = array();
		foreach ($actions as $action) {
			$out[] = $action->$name;
		}
		return $out;
	}

	/*Возвращает список дочерних задач, для которых установлена родительская задача
	 * @param int ID задачи
	 * @param string название елемента в массиве
	 * @return array*/
    function GetLastAction($action_id, $name){

    	$actions = DB::table('llx_actioncomm')
		    ->select('id', 'datep')
		    ->join(DB::raw('(select fk_parent rowid from llx_actioncomm where id='.$action_id.') as parent'), 'parent.rowid', '=', 'id')
		    ->where('active', '=', 1);
    	$result = $actions->first();
    	if($result){
            return $result->$name;
	    }
	    return 0;
	}

	/*Возвращает всю цепочку действия
	  *@param int action_id
	  * @param int id_usr
	  * @param bit только дочерние действия*/
	function getJoinActions($action_id, $id_usr = 0, $only_children = false){
		if(empty($action_id))
			return array(0);
		$chain_actions = array();
		$chain_actions[]=$action_id;

		//Завантажую всі батьківські ІД
		if(!$only_children)
			while($action_id = $this->GetLastAction($action_id, 'id')){
				array_unshift($chain_actions, $action_id);
			}

		//Завантажую всі наступні ІД
		while($tmp_ID = $this->GetNextAction($chain_actions, 'id')){
			$added = false;
			foreach($tmp_ID as $item) {
				if(!in_array($item, $chain_actions)) {
					$added = true;
					$chain_actions[] = $item;
				}
			}
			if(!$added)
				break;
		}

		if(!empty($id_usr)){ //У разі, якщо встановлено ІД користувача, що задіяно в завданнях - видаляю всі задачі, які були створені в цьому ланцюжку до задіяння цього користувача

			$minID = DB::table('llx_actioncomm')
				->select('id')
				->leftJoin('llx_actioncomm_resources', 'fk_actioncomm', '=', 'id')
				->whereIn('llx_actioncomm.id', $chain_actions)
				->where(DB::raw("locate($id_usr, concat(llx_actioncomm.fk_user_action,' ',llx_actioncomm_resources.fk_element))"), ">", 0)->min('id');

			foreach ($chain_actions as $key=>$actionID){
				if($actionID < $minID){
					$chain_actions[$key] = -$chain_actions[$key];
				}
			}
		}

		return $chain_actions;
	}
}
