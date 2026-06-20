<?php
/**
 * phpMyAdmin config для Termux/Android
 * Подключение через TCP (127.0.0.1) вместо unix socket
 * Unix socket MySQL недоступен на Android без root
 */
$cfg['blowfish_secret'] = 'majordomo_termux_secret_key_32ch';

$i = 0;
$i++;
$cfg['Servers'][$i]['auth_type']       = 'cookie';
$cfg['Servers'][$i]['host']            = '127.0.0.1';
$cfg['Servers'][$i]['port']            = 3306;
$cfg['Servers'][$i]['connect_type']    = 'tcp';
$cfg['Servers'][$i]['compress']        = false;
$cfg['Servers'][$i]['AllowNoPassword'] = false;

$cfg['UploadDir'] = '';
$cfg['SaveDir']   = '';
