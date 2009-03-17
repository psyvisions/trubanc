<?php

  // Template for client screens
  // $title - Title string, default: A Trubanc Web Client
  // $bankname - name of bank, default "Trubanc"
  // $menu - html to go to the right of the logo
  // $body - Body html to include, default: template identification text
  // $onload - script to run onload, default: nothing

if (!$title) $title = "A Trubanc Web Client";
if (!$bankname) $bankname = "Trubanc";
if (!$menu) $menu = '';
if (!$body) $body = 'This is the template for Trubanc web client pages';

?>
<html>
<head>
<title><?php echo $title; ?></title>
<meta name="viewport" content="width=device-width"/>
<link rel="apple-touch-icon" href="../site-icon.ico"/>
<link rel="shortcut icon" href="../site-icon.ico"/>
</head>
<body<?php if ($onload) echo " onload='$onload'"; ?>>
<p>
<a href="../">
<img style="vertical-align: middle;border: 1px white" src="../trubanc-logo-50x49.gif" alt="Trubanc" width="50" height="49"/></a>
<b><?php echo $bankname; ?></b>
<?php if ($menu) echo "&nbsp;&nbsp;$menu"; ?>
</p>
<?php echo bankline(); ?>
<?php echo idcode(); ?>
<?php echo $body; ?>
<?php echo $debug; ?>
</body>
</html>
<?

// Copyright 2008 Bill St. Clair
//
// Licensed under the Apache License, Version 2.0 (the "License");
// you may not use this file except in compliance with the License.
// You may obtain a copy of the License at
//
//     http://www.apache.org/licenses/LICENSE-2.0
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions
// and limitations under the License.

?>
