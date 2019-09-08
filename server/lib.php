<?php

require_once("config.php");

function start_transaction($con)
{
    mysqli_query( $con, "BEGIN");
    mysqli_query( $con, "SET TRANSACTION ISOLATION LEVEL SERIALIZABLE");
}

function end_transaction($con, $success)
{
    if($success)
    {
        mysqli_query( $con, "COMMIT");
    }
    else
    {
        mysqli_query( $con, "ROLLBACK");
    }
}

function count_fresh_nodes($con)
{
    global $config;
    
    $freshness = time() - $config["alive_timeout"];
    $freshness = mysqli_real_escape_string( $con, $freshness);
    $sql = "SELECT COUNT(DISTINCT `id`) as total FROM `nodes` WHERE `last_alive` >= $freshness";
    $result = mysqli_query( $con, $sql);
    $data = mysqli_fetch_assoc($result);
    $data = $data['total'];
    
    return $data;
}

function get_con()
{
    global $config;

    //Connect to DB.
    $con = ($GLOBALS["___mysqli_ston"] = mysqli_connect($config["db"]["host"],  $config["db"]["user"],  $config["db"]["pass"]));
    if(!$con) {
        die('Not connected : ' . mysqli_error($GLOBALS["___mysqli_ston"]));
    }

    //Select DB.
    $db_selected = mysqli_select_db( $con, $config["db"]["name"]);
    if(!$db_selected) {
        die('Can\'t use foo : ' . mysqli_error($GLOBALS["___mysqli_ston"]));
    }

    return $con;
}

function get_node($node_id)
{
    global $con;
    
    $node_id = mysqli_real_escape_string( $con, $node_id);
    $sql = "SELECT * FROM `nodes` WHERE `node_id`='$node_id';";
    $result = mysqli_query( $con, $sql);
    $ret = mysqli_fetch_assoc($result);
    
    return $ret;
}

function get_messages($node_id, $list_pop)
{
    global $con;
    
    $node_id = mysqli_real_escape_string( $con, $node_id);
    $list_pop = mysqli_real_escape_string( $con, $list_pop);
    $sql = "SELECT * FROM `messages` WHERE `node_id`='$node_id' AND `list_pop`=$list_pop";
    $result = mysqli_query( $con, $sql);
    $messages = array();
    $old_ids = array();
    while($row = mysqli_fetch_assoc($result))
    {
        $messages[] = $row["message"];
        $old_ids[] = $row["id"];
    }
    
    return array ($messages, $old_ids);
}

function check_password($node_id, $password)
{
    global $con;
    
    $password = mysqli_real_escape_string( $con, $password);
    $node = get_node($node_id);
    if($node == FALSE)
    {
        return 0;
    }
    if($node["password"] != $password)
    {
        return 0;
    }
    
    return $node;
}

function node_last_alive($node)
{
    global $con;
    
    $id = mysqli_real_escape_string( $con, $node["id"]);
    $last_alive = time();
    $last_alive = mysqli_real_escape_string( $con, $last_alive);
    $sql = "UPDATE `nodes` SET `last_alive`=$last_alive WHERE `id`=$id";
    mysqli_query( $con, $sql);
}

function cleanup_messages()
{
    global $con;
    
    $timestamp = time();
    $timestamp = mysqli_real_escape_string( $con, $timestamp);
    $sql = "DELETE FROM `messages` WHERE `cleanup_expiry`<=$timestamp";
    mysqli_query( $con, $sql);
}

?>
