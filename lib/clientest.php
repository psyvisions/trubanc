<?PHP

  // clientest.php
  // Test code for the Trubanc client

require_once "client.php";
require_once "fsdb.php";
require_once "ssl.php";

$db = new fsdb("../clientdb");
$ssl = new ssl();
$client = new client($db, $ssl);

$url = "http://localhost/trubanc";

/*
$server = new serverproxy($url);
echo $server->process("hello") . "\n";
return;
*/

$passphrase = "a really lousy passphrase";

$err = $client->newuser($passphrase, 512);
if ($err) echo "$err\n";

$err = $client->login($passphrase);
if ($err) echo "$err\n";

$id = $client->id;
echo "id: $id\n";

$err = $client->addbank($url);
if ($err) echo "$err\n";
else {
  echo "bankid: " . $client->bankid . "\n";
  //echo "server:"; print_r($client->server);
}

$banks = $client->getbanks();
if (is_string($banks)) echo "getbanks error: $banks\n";
//print_r($banks);

// This fails because the customer has no tokens.
$err = $client->register('John Doe');
if ($err) echo "$err\n";

// This is an example key from servertest.php.
// I'm using it because that ID is in my server testing database.
$privkey = "-----BEGIN RSA PRIVATE KEY-----
MIIBOwIBAAJBAMwfcmkk2coTuYAEbdZ5iXggObNPzbSiDnVtndZFe4/4Xg0IQPfp
Q04OkhWIftMy1OjFhGlBzzNzdW98KYwKMgsCAwEAAQJASAgk4LPPYz84q9NkS1ZS
S6Dbm8pipga2IXxQQaf9ZZ02vWpJR0tTlxq36Zl5P+aAbMck0AvHLgiawx0qWxRz
2QIhAPwyHhCeoZ972KcRi4AIVsWtkGfQsVpVbBOzuFmtAdU9AiEAzzOwvhW6az25
MyxD5VnbEhswT+lDnGAx/WSsHlPqaOcCIQCM0ac7/Heex9Z3ozJTsVRSWNHTRhJh
sGUCs01ytUnauQIgeZrNrRHVgeEM04K0KmPtFZhNZ2jwnFM8o4m1FmuLlJsCIQDc
9tCyRjE3Zj0tXfZL2n6DGeyAc0OsfdQn6V0tFPf6hg==
-----END RSA PRIVATE KEY-----
";

$passphrase2 = "Yes another lousy passphrase";

$pk = $ssl->load_private_key($privkey);
openssl_pkey_export($pk, $privkey, $passphrase2);
openssl_free_key($pk);
//echo $privkey;

$err = $client->newuser($passphrase2, $privkey);
if ($err) echo "$err\n";

$err = $client->login($passphrase2);
if ($err) echo "$err\n";

$id = $client->id;
echo "id: $id\n";

$err = $client->addbank($url);
if ($err) echo "$err\n";
else {
  echo "bankid: " . $client->bankid . "\n";
}

// Succeeds this time, because this ID is already registered at the bank.
$err = $client->register('George Jetson');
if ($err) echo "$err\n";

// Adding yourself as a contact won't happen, but it tests the code
$err = $client->addcontact($id, "Me, myself, and I", "A little note to myself");
if ($err) echo "$err\n";

$contacts = $client->getcontacts();
//print_r($contacts);

$err = $client->initbankaccts();
if ($err) echo "$err\n";

$accts = $client->getaccts();
if (is_string($accts)) echo "$accts\n";
else {
  echo "accts: ";
  $first = true;
  foreach ($accts as $acct) {
    if (!$first) echo ", ";
    echo $acct;
  }
  echo "\n";
}

$asset = $client->getasset('7d4f9b262e46101c4c5dd9234c0bd95ecc878b3c');
if (is_string($asset)) echo "$asset\n";
else print_r($asset);

?>
