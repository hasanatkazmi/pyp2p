<?php

#dht_msg.php?call=register&node_id=node&password=pass&ip=127.0.0.1&port=1337
#dht_msg.php?call=put&node_id=node&password=pass&dest_node_id=node&msg=test
#dht_msg.php?call=list&node_id=node&password=pass
#dht_msg.php?call=get_mutex&node_id=node&password=pass
#dht_msg.php?call=last_alive&node_id=node&password=pass
#dht_msg.php?call=find_neighbours&node_id=node&password=pass

require_once("config.php");
require_once("lib.php");

//Get connection.
$con = get_con();

//Cleanup old DHT + relay messages.
cleanup_messages();

//No cache.
header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
header("Cache-Control: no-cache");
header("Pragma: no-cache");

#Parse URL vars.
$call = "";
if(isset($_GET["call"]))
{
    $call = $_GET["call"];
}

$node_id = "";
if(isset($_GET["node_id"]))
{
    $node_id = $_GET["node_id"];
}

$password = "";
if(isset($_GET["password"]))
{
    $password = $_GET["password"];
}

$ip = $_SERVER['REMOTE_ADDR'];
if(isset($_GET["ip"]))
{
    $ip = $_GET["ip"];
}

$port = "1";
if(isset($_GET["port"]))
{
    $port = $_GET["port"];
}

$network_id = "default";
if(isset($_GET["network_id"]))
{
    $network_id = $_GET["network_id"];
}

$list_pop = 1;
if(isset($_GET["list_pop"]))
{
    $temp = $_GET["list_pop"];
    if(is_numeric($temp))
    {
        if($temp >= 0 && $temp <= 1)
        {
            $list_pop = $temp;
        }
    }
}

if(!empty($call) && !empty($node_id))
{
    #Check password.
    $node = 0;
    if(($call == "list" && $list_pop == 1) || ($call != "register" && $call != "list"))
    {
        $node = check_password($node_id, $password);
        if($node == 0)
        {
            echo("failure");
            $call = "";
        }
    }
    
    
    switch($call)
    {
        case "find_neighbours":
            global $con;
            global $config;
            
            $limit = $config["neighbour_limit"];
            start_transaction($con);
            $timestamp = time();
            $timestamp = mysqli_real_escape_string( $con, $timestamp);
            $freshness = time() - $config["alive_timeout"];
            $freshness = mysqli_real_escape_string( $con, $freshness);
            $network_id = mysqli_real_escape_string( $con, $network_id);
            $nodes = array();
            
            #Fetch one random node to reserve for testing.
            $restrictions = "";
            if($node["has_mutex"] == 1)
            {
                #Fetch nodes to reserve.
                $sql = "SELECT * FROM `nodes` WHERE (`reservation_expiry`<$timestamp  OR `reservation_expiry`=0) AND `last_alive`>=$freshness AND `network_id`='$network_id' ORDER BY rand() LIMIT 1 FOR UPDATE";
                $result = mysqli_query( $con, $sql);
                while($row = mysqli_fetch_assoc($result))
                {
                    $row["can_test"] = 1;
                    $nodes[] = $row;
                    $node_id = mysqli_real_escape_string( $con, $row["id"]);
                    $restrictions = " AND `id`<>$node_id";
                }
                
                #Reserve those nodes.
                $expiry = time() + $config["reservation_timeout"];
                $expiry = mysqli_real_escape_string( $con, $expiry);
                foreach($nodes as $value)
                {
                    $id = $value["id"];
                    $id = mysqli_real_escape_string( $con, $id);
                    $sql = "UPDATE `nodes` SET `reservation_expiry`=$expiry WHERE `id`=$id";
                    mysqli_query( $con, $sql);
                }
                
                #Reduce limit for next section (since we just got a node.)
                $limit -= 1;
            }
            
            #Fetch remaining nodes.
            if($limit)
            {
                $sql = "SELECT * FROM `nodes` WHERE `last_alive`>=$freshness AND `network_id`='$network_id' $restrictions ORDER BY rand() LIMIT $limit FOR UPDATE";
                $result = mysqli_query( $con, $sql);
                while($row = mysqli_fetch_assoc($result))
                {
                    $row["can_test"] = 0;
                    $nodes[] = $row;
                }
            }
            
            end_transaction($con, 1);
            
            $neighbours = array();
            foreach($nodes as $value)
            {
                $neighbour = array();
                $neighbour["ip"] = $value["ip"];
                $neighbour["port"] = $value["port"];
                $neighbour["id"] = $value["node_id"];
                $neighbour["can_test"] = $value["can_test"];
                if($value["node_id"] == $node["node_id"])
                {
                    continue;
                }
                $neighbours[] = $neighbour;
            }
            
            #Return messages as JSON.
            echo(json_encode($neighbours));
            break;
            
        case "last_alive":
            #Update node last alive.
            node_last_alive($node);
            
            echo("success");
            break;
        
        case "get_mutex":
            global $con;
            global $config;
                        
            #Get mutex -- causes new nodes that join to have evenly distributed
            #mutexes so tests line up perfectly. After that - its random.
            start_transaction($con);
            #mysql_query("LOCK TABLES `nodes` WRITE", $con);
            $node_id = mysqli_real_escape_string( $con, $node["id"]);
            $sql = "SELECT * FROM `nodes` WHERE `id`=" . $node_id . "FOR UPDATE";
            mysqli_query( $con, $sql);
            $fresh_node_no = count_fresh_nodes($con);
            #$config["neighbour_limit"] + 1
            if($fresh_node_no % 2 == 0 && $fresh_node_no != 0)
            {
                $has_mutex = 1;
            }
            else
            {
                $has_mutex = 0;
            }
            
            #Already has a mutex -- use random.
            if($node["has_mutex"] != -1)
            {
                $has_mutex = rand(0, 1);
            }
            
            
            echo(htmlspecialchars($has_mutex));
            
            $id = $node["id"];
            $last_alive = time();
            $id = mysqli_real_escape_string( $con, $id);
            $last_alive = mysqli_real_escape_string( $con, $last_alive);
            $sql = "UPDATE `nodes` SET `has_mutex`=$has_mutex,`last_alive`=$last_alive WHERE `id`=$id";
            mysqli_query( $con, $sql);
            #mysql_query("UNLOCK TABLES", $con);
            end_transaction($con, 1);
            
            break;
        
        case "register":
            global $con;
        
            if(empty($password))
            {
                break;
            }

            #Register new node.
            if(get_node($node_id) == FALSE)
            {
                $node_id = mysqli_real_escape_string( $con, $node_id);
                $password = mysqli_real_escape_string( $con, $password);
                $ip = mysqli_real_escape_string( $con, $ip);
                $port = mysqli_real_escape_string( $con, $port);
                $network_id = mysqli_real_escape_string( $con, $network_id);
                $timestamp = time();
                $sql = "INSERT INTO `nodes` (`node_id`, `ip`, `port`, `password`, `last_alive`, `reservation_expiry`, `network_id`) VALUES ('$node_id', '$ip', $port, '$password', $timestamp, 0, '$network_id');";
                mysqli_query($GLOBALS["___mysqli_ston"], $sql);
            }
            else
            {
                echo("Already registered.");
            }
            
            echo("success");
            break;

        case "put":
            global $con;
            global $config;
        
            $msg = $_GET["msg"];
            if(empty($msg))
            {
                echo("failure");
                break;
            }
            
            $dest_node_id = $_GET["dest_node_id"];
            if(empty($dest_node_id))
            {
                echo("failure");
                break;
            }
            
            #Put message into DB.
            $msg = mysqli_real_escape_string( $con, $msg);
            $dest_node_id = mysqli_real_escape_string( $con, $dest_node_id);
            $timestamp = time();
            $expiry = time() + $config["message_timeout"];
            $list_pop = mysqli_real_escape_string( $con, $list_pop);
            $timestamp = mysqli_real_escape_string( $con, $timestamp);
            $expiry = mysqli_real_escape_string( $con, $expiry);
            $sql = "INSERT INTO `messages` (`node_id`, `message`, `list_pop`, `timestamp`, `cleanup_expiry`) VALUES ('$dest_node_id', '$msg', $list_pop, $timestamp, $expiry);";
            mysqli_query($GLOBALS["___mysqli_ston"], $sql);
            echo("success");
            break;

        case "list":
            global $con;
            global $config;
            
            if($config["long_polling"])
            {
                $messages = array();
                while(!count($messages))
                {
                    // Long poll - kind of.
                    list ($messages, $old_ids) = get_messages($node_id, $list_pop);
                    usleep(10000);
                }
            }
            else
            {
                list ($messages, $old_ids) = get_messages($node_id, $list_pop);
            }

            #Delete old messages.
            $node_id = mysqli_real_escape_string( $con, $node_id);
            foreach($old_ids as $old_id)
            {
                $old_id = mysqli_real_escape_string( $con, $old_id);
                $sql = "DELETE FROM `messages` WHERE `id`='$old_id' AND `list_pop`=1";
                mysqli_query($GLOBALS["___mysqli_ston"], $sql);
            }
            
            #Update node last alive.
            if($node != 0)
            {
                node_last_alive($node);
            }

            #Return messages as JSON.
            echo(json_encode($messages));

            break;

        default:
            break;
    }
}

((is_null($___mysqli_res = mysqli_close($con))) ? false : $___mysqli_res);
flush();
ob_flush();

?>
