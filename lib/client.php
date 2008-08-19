<?PHP

  // client.php
  // A Trubanc client API. Talks the protocol of server.php

require_once "tokens.php";
require_once "ssl.php";
require_once "utility.php";
require_once "parser.php";

class client {

  var $db;
  var $ssl;
  var $t;                       // tokens instance
  var $parser;                  // parser instance
  var $u;                       // utility instance
  var $pubkeydb;

  // Initialized by login() and newuser()
  var $id;
  var $privkey;

  // initialized by setbank() and addbank()
  var $server;
  var $bankid;

  var $unpack_reqs_key = 'unpack_reqs';

  // $db is an object that does put(key, value), get(key), and dir(key)
  // $ssl is an object that does the protocol of ssl.php
  function client($db, $ssl=false) {
    $this->db = $db;
    if (!$ssl) $ssl = new ssl();
    $this->ssl = $ssl;
    $this->t = new tokens();
    $this->pubkeydb = new pubkeydb($this, $db->subdir($this->t->PUBKEY));
    $this->parser = new parser($this->pubkeydb, $ssl);
    $this->u = new utility($this->t, $this->parser, $this);
  }

  // API Methods
  // If the return is not specified, it will be false or an error string

  // Create a new user with the given passphrase, error if already there.
  // If $privkey is a string, use that as the private key.
  // If it is an integer, default 3072, create a new private key with that many bits
  // User is logged in when this returns successfully.
  function newuser($passphrase, $privkey=3072) {
    $db = $this->db;
    $t = $this->t;
    $ssl = $this->ssl;

    $this->logout();
    $hash = $this->passphrasehash($passphrase);
    if ($db->get($t->PRIVKEY . "/$hash")) {
        return "Passphrase already has an associated private key";
    }
    if (!is_string($privkey)) {
      if (!is_numeric($privkey)) return "privkey arg not a string or number";
      $privkey = $ssl->make_privkey($privkey, $passphrase);
    }
    $privkeystr = $privkey;
    $privkey = $ssl->load_private_key($privkey, $passphrase);
    if (!$privkey) return "Could not load private key";
    $pubkey = $ssl->privkey_to_pubkey($privkey);
    
    $id = $ssl->pubkey_id($pubkey);
    $db->put($t->PRIVKEY . "/$hash", $privkeystr);
    $db->put($this->pubkeykey($id), trim($pubkey) . "\n");

    $this->id = $id;
    $this->privkey = $privkey;
    return false;
  }

  // Log in with the given passphrase. Error if no user associated with passphrase.
  function login($passphrase) {
    $db = $this->db;
    $t = $this->t;
    $ssl = $this->ssl;

    $this->logout();
    $hash = $this->passphrasehash($passphrase);
    $privkey = $db->GET($t->PRIVKEY . "/$hash");
    if (!$privkey) return "No account for passphrase in database";
    $privkey = $ssl->load_private_key($privkey, $passphrase);
    if (!$privkey) return "Could not load private key";
    $pubkey = $ssl->privkey_to_pubkey($privkey);
    $id = $ssl->pubkey_id($pubkey);

    $this->id = $id;
    $this->privkey = $privkey;
    return false;
  }

  function logout() {
    $ssl = $this->ssl;

    $this->id = false;
    $privkey = $this->privkey;
    if ($privkey) {
      $this->privkey = false;
      $ssl->free_privkey($privkey);
    }
    $this->bankid = false;
    $this->server = false;
  }

  // All the API methods below require the user to be logged in.
  // $id and $privkey must be set.

  // Return true if logged in
  function loggedinp() {
    return $this->id && $this->privkey;
  }

  // Return all the banks known by the current user:
  // array(array($t->BANKID => $bankid,
  //             $t->NAME => $name,
  //             $t->URL => $url), ...)
  // $pubkeysig will be blank if the user has no account at the bank.
  function getbanks() {
    $t = $this->t;
    $db = $this->db;
    $id = $this->id;

    if (!$this->loggedinp()) return "Not logged in";

    $banks = $db->contents($t->ACCOUNT . "/$id");
    $res = array();
    foreach ($banks as $bankid) {
      $bank = array($t->BANKID => $bankid,
                    $t->NAME => $this->bankprop($t->NAME),
                    $t->URL => $this->bankprop($t->URL));
      $res[] = $bank;
    }
    return $res;
  }

  // Add a bank with the given URL to the database.
  // No error, but does nothing, if the bank is already there.
  // Sets the client instance to use this bank until addbank() or setbank()
  // is called to change it.
  function addbank($url) {
    $db = $this->db;
    $t = $this->t;

    if (!$this->loggedinp()) return "Not logged in";

    // Hash the URL to ensure its name will work as a file name
    $urlhash = sha1($url);
    $urlkey = $t->BANK . '/' . $t->BANKID;
    $bankid = $db->get("$urlkey/$urlhash");
    if ($bankid) return $this->setbank($bankid);

    $u = $this->u;
    $id = $this->id;
    $privkey = $this->privkey;
    $ssl = $this->ssl;
    $parser = $this->parser;

    $server = new serverproxy($url);
    $this->server = $server;
    $pubkey = $ssl->privkey_to_pubkey($privkey);
    $msg = $this->sendmsg($t->BANKID, $pubkey);
    $args = $u->match_message($msg);
    if (is_string($args)) return "Bank's bankid message wrong: $msg";
    $bankid = $args[$t->CUSTOMER];
    if ($args[$t->REQUEST] != $t->REGISTER ||
        $args[$t->BANKID] != $bankid) {
      return "Bank's bankid message wrong: $msg";
    }
    $pubkey = $args[$t->PUBKEY];
    $name = $args[$t->NAME];
    if ($ssl->pubkey_id($pubkey) != $bankid) {
      return "Bank's id doesn't match its public key: $msg";
    }

    // Initialize the bank in the database
    $this->bankid = $bankid;
    $db->put("$urlkey/$urlhash", $bankid);
    $db->put($this->bankkey($t->URL), $url);
    $db->put($this->bankkey($t->NAME), $name);
    $db->put($this->pubkeykey($bankid), trim($pubkey) . "\n");

    // Mark the user as knowing about this bank
    // Also mark this account as not yet being synced with bank
    $db->put($this->userbankkey($t->REQ), -1);

    return false;
  }

  // Set the bank to the given id.
  // Sets the client instance to use this bank until addbank() or setbank()
  // is called to change it, by setting $this->bankid and $this->server
  function setbank($bankid) {
    $db = $this->db;
    $t = $this->t;

    if (!$this->loggedinp()) return "Not logged in";

    $this->bankid = $bankid;

    $url = $this->bankprop($t->URL);
    if (!$url) return "Bank not known: $bankid";
    $server = new serverproxy($url);
    $this->server = $server;

    $req = $this->userbankprop($t->REQ);
    if (!$req) {
      $db->put($this->userbankkey($t->REQ), -1);
    }

    return false;
  }

  // All the API methods below require the user to be logged and the bank to be set.
  // Do this by calling newuser() or login(), and addbank() or setbank().
  // $this->id, $this->privkey, $this->bankid, & $this->server must be set.

  // Return true if the user is logged in and the bank is set
  function banksetp() {
    return $this->loggedinp() && $this->bankid && $this->server;
  }

  // Register at the current bank.
  // No error if already registered
  function register($name='') {
    $t = $this->t;
    $u = $this->u;
    $db = $this->db;
    $id = $this->id;
    $bankid = $this->bankid;
    $ssl = $this->ssl;

    if (!$this->banksetp()) return "Bank not set";

    // If already registered and we know it, nothing to do
    if ($this->userbankprop($t->PUBKEYSIG)) return false;

    // See if bank already knows us
    // Resist the urge to change this to a call to
    // get_pubkey_from_server. Trust me.
    $msg = $this->sendmsg($t->ID, $bankid, $id);
    $args = $this->match_bankmsg($msg, $t->ATREGISTER);
    if (is_string($args)) {
      // Bank doesn't know us. Register with bank.
      $msg = $this->sendmsg($t->REGISTER, $bankid, $this->pubkey($id), $name);
      $args = $this->match_bankmsg($msg, $t->ATREGISTER);
    }
    if (is_string($args)) return "Registration failed: $args";

    // Didn't fail. Notice registration here
    $args = $args[$t->MSG];
    if ($args[$t->CUSTOMER] != $id ||
        $args[$t->REQUEST] != $t->REGISTER ||
        $args[$t->BANKID] != $bankid) return "Malformed registration message";
    $pubkey = $args[$t->PUBKEY];
    $keyid = $ssl->pubkey_id($pubkey);
    if ($keyid != $id) return "Server's pubkey wrong";
    $db->put($this->userbankkey($t->PUBKEYSIG), $msg);

    return false;
  }

  // Get contacts for the current bank.
  // Returns an error string or an array of items of the form:
  //
  //   array($t->ID, $id,
  //         $t->NAME, $name,
  //         $t->NICKNAME, $nickname,
  //         $t->NOTE, $note)
  function getcontacts() {
    $t = $this->t;
    $db = $this->db;

    if (!$this->banksetp()) return "Bank not set";

    $ids = $db->contents($this->contactkey());
    $res = array();
    foreach ($ids as $otherid) {
      $res[] = array($t->ID => $otherid,
                     $t->NAME => $this->contactprop($otherid, $t->NAME),
                     $t->NICKNAME => $this->contactprop($otherid, $t->NICKNAME),
                     $t->NOTE => $this->contactprop($otherid, $t->NOTE));
    }
    return $res;
  }

  // Add a contact to the current bank.
  // If it's already there, change its nickname and note, if included
  function addcontact($otherid, $nickname=false, $note=false) {
    $t = $this->t;
    $db = $this->db;
    $pubkeydb = $this->pubkeydb;
    $bankid = $this->bankid;
    $ssl = $this->ssl;

    if (!$this->banksetp()) return "Bank not set";

    if ($this->contactprop($otherid, $t->PUBKEYSIG)) {
      if ($nickname) $db->put($this->contactkey($otherid, $t->NICKNAME), $nickname);
      if ($note) $db->put($this->contactkey($otherid, $t->NOTE), $note);
      return false;
    }

    $msg = $this->sendmsg($t->ID, $bankid, $otherid);
    $args = $this->match_bankmsg($msg, $t->ATREGISTER);
    if (is_string($args)) return $args;
    $args = $args[$t->MSG];
    $pubkey = $args[$t->PUBKEY];
    $name = $args[$t->NAME];
    if ($otherid != $ssl->pubkey_id($pubkey)) {
      return "pubkey from server doesn't match ID";
    }

    if (!$nickname) $nickname = $name ? $name : 'anonymous';
    $db->put($this->contactkey($otherid, $t->NICKNAME), $nickname);
    $db->put($this->contactkey($otherid, $t->NOTE), $note);
    $db->put($this->contactkey($otherid, $t->NAME), $name);
    $db->put($this->contactkey($otherid, $t->PUBKEYSIG), $msg);
  }

  // GET sub-account names.
  // Returns an error string or an array of the sub-account names
  function getaccts() {

    if (!$this->banksetp()) return "Bank not set";

  }

  // Get user balances for all sub-accounts or just one.
  // Returns an error string or an array of items of the form:
  //
  //    array($t->ASSET => $assetid,
  //          $t->ASSETNAME => $assetname,
  //          $t->AMOUNT => $amount,
  //          $t->FORMATTEDAMOUNT => $formattedamount,
  //          $t->ACCT => $acct)
  //
  // where $assetid & $assetname describe the asset, $amount is the
  // amount, as an integer, $formattedamount is the amount as a
  // decimal number with the scale and precision applied, and $acct
  // is the name of the sub-account, default $t->MAIN.
  //
  // The $acct arg is true for all sub-accounts, false for the
  // $t->MAIN sub-account, or a string for that sub-account only
  function getbalance($acct=true) {

    if (!$this->banksetp()) return "Bank not set";

  }

  // Initiate a spend
  function spend($toid, $asset, $amount, $acct=false) {

    if (!$this->banksetp()) return "Bank not set";

  }

  // Transfer from one sub-account to another
  function transfer($asset, $amount, $fromacct, $toacct) {

    if (!$this->banksetp()) return "Bank not set";

  }

  // Get the inbox contents.
  // Returns an error string, or an array of inbox entries, each of which is
  // of one of the form:
  //
  //   array($t->REQUEST => $request
  //         $t->ID => $fromid,
  //         $t->TIME => $time,
  //         $t->ASSET => $assetid,
  //         $t->ASSETNAME => $assetname,
  //         $t->AMOUNT => $amount,
  //         $t->FORMATTEDAMOUNT => $formattedamount,
  //         $t->NOTE => $note)
  //
  // Where $request is $t->SPEND, $t->SPENDACCEPT, or $t->SPENDREJECT,
  // $fromid is the ID of the sender of the inbox entry,
  // $time is the timestamp from the bank on the inbox entry,
  // $assetid & $assetname describe the asset being transferred,
  // $amount is the amount of the asset being transferred, as an integer,
  // $formattedamount is the amount as a decimal number with the scale
  // and precision applied,
  // and $NOTE is the note that came from the sender.
  function getinbox() {

    if (!$this->banksetp()) return "Bank not set";

  }

  // Process the inbox contents.
  // $directions is an array of items of the form:
  //
  //  array($t->TIME => $time,
  //        $t->REQUEST => $request,
  //        $t->NOTE => $note)
  //
  // where $time is a timestamp in the inbox,
  // $request is $t->SPENDACCEPT or $t->SPENDREJECT, or omitted for
  // processing an accept or reject from a former spend recipient,
  // and $note is the note to go with the accept or reject.
  function processinbox($directions) {

    if (!$this->banksetp()) return "Bank not set";

  }

  // Get the outbox contents.
  // Returns an error string or the outbox contents as an array of
  // items of the form:
  //
  //   array($t->ID => $recipientid,
  //         $t->ASSET => $assetid,
  //         $t->ASSETNAME => $assetname,
  //         $t->AMOUNT => $amount,
  //         $t->FORMATTEDAMOUNT => formattedamount,
  //         $t->NOTE => $note)
  function getoutbox() {

    if (!$this->banksetp()) return "Bank not set";

  }

  // End of API methods

  // For utility->bankgetter
  function bankid() {
    return $this->bankid;
  }

  function passphrasehash($passphrase) {
    return sha1(trim($passphrase));
  }

  // Create a signed customer message.
  // Takes an arbitrary number of args.
  function custmsg() {
    $id = $this->id;
    $u = $this->u;
    $ssl = $this->ssl;
    $privkey = $this->privkey;

    $args = func_get_args();
    $args = array_merge(array($id), $args);
    $msg = $u->makemsg($args);
    $sig = $ssl->sign($msg, $privkey);
    return "$msg:\n$sig";
  }

  // Send a customer message to the server.
  // Takes an arbitrary number of args.
  function sendmsg() {
    $server = $this->server;

    $req = func_get_args();
    $msg = call_user_func_array(array($this, 'custmsg'), $req);
    //echo "Sending: $msg\n";
    return $server->process($msg);
  }

  // Unpack a bank message
  // Return a string if parse error or fail from bank
  function match_bankmsg($msg, $request=false) {
    $t = $this->t;
    $u = $this->u;
    $parser = $this->parser;
    $bankid = $this->bankid;

    $reqs = $parser->parse($msg);
    if (!$reqs) return "Parse error: " . $parser->errmsg;

    $req = $reqs[0];
    $args = $u->match_pattern($req);
    if (is_string($args)) return "While matching: $args";
    if ($args[$t->CUSTOMER] != $bankid) return "Return message not from bank";
    if ($args[$t->REQUEST] == $t->FAILED) return $args[$t->ERRMSG];
    if ($request && $args[$t->REQUEST] != $request) {
      return "Wrong return type from bank: $msg";
    }
    if ($args[$t->MSG]) {
      $msgargs = $u->match_pattern($args[$t->MSG]);
      if (is_string($msgargs)) return "While matching bank-wrapped msg: $msgargs";
      $args[$t->MSG] = $msgargs;
    }
    $args[$this->unpack_reqs_key] = $reqs; // save parse results
    return $args;
  }

  function pubkey($id) {
    $db = $this->pubkeydb;
    return $db->get($id);
  }

  function pubkeykey($id) {
    $t = $this->t;
    return $t->PUBKEY . "/$id";
  }

  function bankkey($prop=false) {
    $t = $this->t;
    $bankid = $this->bankid;

    $key = $t->BANK . "/$bankid";
    return $prop ? "$key/$prop" : $key;
  }

  function bankprop($prop) {
    $db = $this->db;

    return $db->get($this->bankkey($prop));
  }

  function userbankkey($prop=false) {
    $t = $this->t;
    $id = $this->id;
    $bankid = $this->bankid;

    $key = $t->ACCOUNT . "/$id/$bankid";
    return $prop ? "$key/$prop" : $key;
  }

  function userbankprop($prop) {
    $db = $this->db;

    return $db->get($this->userbankkey($prop));
  }

  function contactkey($otherid=false, $prop=false) {
    $t = $this->t;
    $id = $this->id;
    $bankid = $this->bankid;

    $res = $t->ACCOUNT . "/$id/$bankid/" . $t->CONTACT;
    if ($otherid) {
      $res .= "/$otherid";
      if ($prop) $res .= "/$prop";
    }
    return $res;
  }

  function contactprop($otherid, $prop) {
    $db = $this->db;

    return $db->get($this->contactkey($otherid, $prop));
  }

  // Send a t->ID command to the server, if there is one.
  // Parse out the pubkey, cache it in the database, and return it.
  // Return the empty string if there is no server or it doesn't know
  // the id.
  // If $wholemsg is true, return the $args for the whole $t->REGISTER
  // message, intead of just the pubkey, and return an error message,
  // instead of the empty string, if there's a problem.
  function get_pubkey_from_server($id, $wholemsg=false) {
    $t = $this->t;
    $db = $this->db;
    $bankid = $this->bankid;

    if (!$this->banksetp()) return $wholemsg ? 'Bank not set' : '';

    $msg = $this->sendmsg($t->ID, $bankid, $id);
    $args = $this->match_bankmsg($msg, $t->ATREGISTER);
    if (is_string($args)) return $wholemsg ? $args : '';
    $args = $args[$t->MSG];
    $pubkey = $args[$t->PUBKEY];
    $pubkeykey = $this->pubkeykey($id);
    if ($pubkey) {
      if (!$db->get($pubkeykey)) $db->put($pubkeykey, $pubkey);
      if ($wholemsg) return $args;
      return $pubkey;
    }
    return $wholemsg ? "Can't find pubkey on server" : '';
  }
}

class serverproxy {
  var $url;

  function serverproxy($url) {
    if (substr($url,-1) == '/') $url = substr($url, 0, -1);
    $this->url = $url;
  }

  function process($msg) {
    $url = $this->url;
    return file_get_contents("$url/?msg=" . urlencode($msg));
  }
}

// Look up a public key, from the client database first, then from the
// current bank.
class pubkeydb {

  var $client;
  var $pubkeydb;

  function pubkeydb($client, $pubkeydb) {
    $this->client = $client;
    $this->pubkeydb = $pubkeydb;
  }

  function get($id) {
    $pubkeydb = $this->pubkeydb;
    $client = $this->client;

    $res = $pubkeydb->get($id);
    if ($res) return $res;

    return $client->get_pubkey_from_server($id);
  }
}

?>
