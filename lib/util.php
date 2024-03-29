<?php
/*
 * StatusNet - the distributed open-source microblogging tool
 * Copyright (C) 2008, 2009, StatusNet, Inc.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/* XXX: break up into separate modules (HTTP, user, files) */

// Show a server error

function common_server_error($msg, $code=500)
{
    $err = new ServerErrorAction($msg, $code);
    $err->showPage();
}

// Show a user error
function common_user_error($msg, $code=400)
{
    $err = new ClientErrorAction($msg, $code);
    $err->showPage();
}

function common_init_locale($language=null)
{
    if(!$language) {
        $language = common_language();
    }
    putenv('LANGUAGE='.$language);
    putenv('LANG='.$language);
    return setlocale(LC_ALL, $language . ".utf8",
                     $language . ".UTF8",
                     $language . ".utf-8",
                     $language . ".UTF-8",
                     $language);
}

function common_init_language()
{
    mb_internal_encoding('UTF-8');
    $language = common_language();
    // So we don't have to make people install the gettext locales
    $locale_set = common_init_locale($language);
    bindtextdomain("statusnet", common_config('site','locale_path'));
    bind_textdomain_codeset("statusnet", "UTF-8");
    textdomain("statusnet");
    setlocale(LC_CTYPE, 'C');
    if(!$locale_set) {
        common_log(LOG_INFO,'Language requested:'.$language.' - locale could not be set:',__FILE__);
    }
}

function common_timezone()
{
    if (common_logged_in()) {
        $user = common_current_user();
        if ($user->timezone) {
            return $user->timezone;
        }
    }

    return common_config('site', 'timezone');
}

function common_language()
{

    // If there is a user logged in and they've set a language preference
    // then return that one...
    if (_have_config() && common_logged_in()) {
        $user = common_current_user();
        $user_language = $user->language;
        if ($user_language)
          return $user_language;
    }

    // Otherwise, find the best match for the languages requested by the
    // user's browser...
    $httplang = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : null;
    if (!empty($httplang)) {
        $language = client_prefered_language($httplang);
        if ($language)
          return $language;
    }

    // Finally, if none of the above worked, use the site's default...
    return common_config('site', 'language');
}
// salted, hashed passwords are stored in the DB

function common_munge_password($password, $id)
{
    return md5($password . $id);
}

// check if a username exists and has matching password
function common_check_user($nickname, $password)
{
    // NEVER allow blank passwords, even if they match the DB
    if (mb_strlen($password) == 0) {
        return false;
    }
    $user = User::staticGet('nickname', $nickname);
    if (is_null($user) || $user === false) {
        return false;
    } else {
        if (0 == strcmp(common_munge_password($password, $user->id),
                        $user->password)) {
            return $user;
        } else {
            return false;
        }
    }
}

// is the current user logged in?
function common_logged_in()
{
    return (!is_null(common_current_user()));
}

function common_have_session()
{
    return (0 != strcmp(session_id(), ''));
}

function common_ensure_session()
{
    $c = null;
    if (array_key_exists(session_name(), $_COOKIE)) {
        $c = $_COOKIE[session_name()];
    }
    if (!common_have_session()) {
        if (common_config('sessions', 'handle')) {
            Session::setSaveHandler();
        }
        @session_start();
        if (!isset($_SESSION['started'])) {
            $_SESSION['started'] = time();
            if (!empty($c)) {
                common_log(LOG_WARNING, 'Session cookie "' . $_COOKIE[session_name()] . '" ' .
                           ' is set but started value is null');
            }
        }
    }
}

// Three kinds of arguments:
// 1) a user object
// 2) a nickname
// 3) null to clear

// Initialize to false; set to null if none found

$_cur = false;

function common_set_user($user)
{

    global $_cur;

    if (is_null($user) && common_have_session()) {
        $_cur = null;
        unset($_SESSION['userid']);
        return true;
    } else if (is_string($user)) {
        $nickname = $user;
        $user = User::staticGet('nickname', $nickname);
    } else if (!($user instanceof User)) {
        return false;
    }

    if ($user) {
        common_ensure_session();
        $_SESSION['userid'] = $user->id;
        $_cur = $user;
        return $_cur;
    }
    return false;
}

function common_set_cookie($key, $value, $expiration=0)
{
    $path = common_config('site', 'path');
    $server = common_config('site', 'server');

    if ($path && ($path != '/')) {
        $cookiepath = '/' . $path . '/';
    } else {
        $cookiepath = '/';
    }
    return setcookie($key,
                     $value,
                     $expiration,
                     $cookiepath,
                     $server);
}

define('REMEMBERME', 'rememberme');
define('REMEMBERME_EXPIRY', 30 * 24 * 60 * 60); // 30 days

function common_rememberme($user=null)
{
    if (!$user) {
        $user = common_current_user();
        if (!$user) {
            common_debug('No current user to remember', __FILE__);
            return false;
        }
    }

    $rm = new Remember_me();

    $rm->code = common_good_rand(16);
    $rm->user_id = $user->id;

    // Wrap the insert in some good ol' fashioned transaction code

    $rm->query('BEGIN');

    $result = $rm->insert();

    if (!$result) {
        common_log_db_error($rm, 'INSERT', __FILE__);
        common_debug('Error adding rememberme record for ' . $user->nickname, __FILE__);
        return false;
    }

    $rm->query('COMMIT');

    common_debug('Inserted rememberme record (' . $rm->code . ', ' . $rm->user_id . '); result = ' . $result . '.', __FILE__);

    $cookieval = $rm->user_id . ':' . $rm->code;

    common_log(LOG_INFO, 'adding rememberme cookie "' . $cookieval . '" for ' . $user->nickname);

    common_set_cookie(REMEMBERME, $cookieval, time() + REMEMBERME_EXPIRY);

    return true;
}

function common_remembered_user()
{

    $user = null;

    $packed = isset($_COOKIE[REMEMBERME]) ? $_COOKIE[REMEMBERME] : null;

    if (!$packed) {
        return null;
    }

    list($id, $code) = explode(':', $packed);

    if (!$id || !$code) {
        common_log(LOG_WARNING, 'Malformed rememberme cookie: ' . $packed);
        common_forgetme();
        return null;
    }

    $rm = Remember_me::staticGet($code);

    if (!$rm) {
        common_log(LOG_WARNING, 'No such remember code: ' . $code);
        common_forgetme();
        return null;
    }

    if ($rm->user_id != $id) {
        common_log(LOG_WARNING, 'Rememberme code for wrong user: ' . $rm->user_id . ' != ' . $id);
        common_forgetme();
        return null;
    }

    $user = User::staticGet($rm->user_id);

    if (!$user) {
        common_log(LOG_WARNING, 'No such user for rememberme: ' . $rm->user_id);
        common_forgetme();
        return null;
    }

    // successful!
    $result = $rm->delete();

    if (!$result) {
        common_log_db_error($rm, 'DELETE', __FILE__);
        common_log(LOG_WARNING, 'Could not delete rememberme: ' . $code);
        common_forgetme();
        return null;
    }

    common_log(LOG_INFO, 'logging in ' . $user->nickname . ' using rememberme code ' . $rm->code);

    common_set_user($user);
    common_real_login(false);

    // We issue a new cookie, so they can log in
    // automatically again after this session

    common_rememberme($user);

    return $user;
}

// must be called with a valid user!

function common_forgetme()
{
    common_set_cookie(REMEMBERME, '', 0);
}

// who is the current user?
function common_current_user()
{
    global $_cur;

    if (!_have_config()) {
        return null;
    }

    if ($_cur === false) {

        if (isset($_REQUEST[session_name()]) || (isset($_SESSION['userid']) && $_SESSION['userid'])) {
            common_ensure_session();
            $id = isset($_SESSION['userid']) ? $_SESSION['userid'] : false;
            if ($id) {
                $_cur = User::staticGet($id);
                return $_cur;
            }
        }

        // that didn't work; try to remember; will init $_cur to null on failure
        $_cur = common_remembered_user();

        if ($_cur) {
            common_debug("Got User " . $_cur->nickname);
            common_debug("Faking session on remembered user");
            // XXX: Is this necessary?
            $_SESSION['userid'] = $_cur->id;
        }
    }

    return $_cur;
}

// Logins that are 'remembered' aren't 'real' -- they're subject to
// cookie-stealing. So, we don't let them do certain things. New reg,
// OpenID, and password logins _are_ real.

function common_real_login($real=true)
{
    common_ensure_session();
    $_SESSION['real_login'] = $real;
}

function common_is_real_login()
{
    return common_logged_in() && $_SESSION['real_login'];
}

// get canonical version of nickname for comparison
function common_canonical_nickname($nickname)
{
    // XXX: UTF-8 canonicalization (like combining chars)
    return strtolower($nickname);
}

// get canonical version of email for comparison
function common_canonical_email($email)
{
    // XXX: canonicalize UTF-8
    // XXX: lcase the domain part
    return $email;
}

function common_render_content($text, $notice)
{
    $r = common_render_text($text);
    $id = $notice->profile_id;
    $r = preg_replace('/(^|\s+)@([A-Za-z0-9]{1,64})/e', "'\\1@'.common_at_link($id, '\\2')", $r);
    $r = preg_replace('/^T ([A-Z0-9]{1,64}) /e', "'T '.common_at_link($id, '\\1').' '", $r);
    $r = preg_replace('/(^|\s+)@#([A-Za-z0-9]{1,64})/e', "'\\1@#'.common_at_hash_link($id, '\\2')", $r);
    $r = preg_replace('/(^|\s)!([A-Za-z0-9]{1,64})/e', "'\\1!'.common_group_link($id, '\\2')", $r);
    return $r;
}

function common_render_text($text)
{
    $r = htmlspecialchars($text);

    $r = preg_replace('/[\x{0}-\x{8}\x{b}-\x{c}\x{e}-\x{19}]/', '', $r);
    $r = common_replace_urls_callback($r, 'common_linkify');
    $r = preg_replace('/(^|\&quot\;|\'|\(|\[|\{|\s+)#([\pL\pN_\-\.]{1,64})/e', "'\\1#'.common_tag_link('\\2')", $r);
    // XXX: machine tags
    return $r;
}

function common_replace_urls_callback($text, $callback, $notice_id = null) {
    // Start off with a regex
    $regex = '#'.
    '(?:^|[\s\(\)\[\]\{\}\\\'\\\";]+)(?![\@\!\#])'.
    '('.
        '(?:'.
            '(?:'. //Known protocols
                '(?:'.
                    '(?:(?:https?|ftps?|mms|rtsp|gopher|news|nntp|telnet|wais|file|prospero|webcal|irc)://)'.
                    '|'.
                    '(?:(?:mailto|aim|tel|xmpp):)'.
                ')'.
                '(?:[\pN\pL\-\_\+]+(?::[\pN\pL\-\_\+]+)?\@)?'. //user:pass@
                '(?:'.
                    '(?:'.
                        '\[[\pN\pL\-\_\:\.]+(?<![\.\:])\]'. //[dns]
                    ')|(?:'.
                        '[\pN\pL\-\_\:\.]+(?<![\.\:])'. //dns
                    ')'.
                ')'.
            ')'.
            '|(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)'. //IPv4
            '|(?:'. //IPv6
                '\[?(?:(?:(?:[0-9A-Fa-f]{1,4}:){7}(?:(?:[0-9A-Fa-f]{1,4})|:))|(?:(?:[0-9A-Fa-f]{1,4}:){6}(?::|(?:(?:25[0-5]|2[0-4]\d|[01]?\d{1,2})(?:\.(?:25[0-5]|2[0-4]\d|[01]?\d{1,2})){3})|(?::[0-9A-Fa-f]{1,4})))|(?:(?:[0-9A-Fa-f]{1,4}:){5}(?:(?::(?:(?:25[0-5]|2[0-4]\d|[01]?\d{1,2})(?:\.(?:25[0-5]|2[0-4]\d|[01]?\d{1,2})){3})?)|(?:(?::[0-9A-Fa-f]{1,4}){1,2})))|(?:(?:[0-9A-Fa-f]{1,4}:){4}(?::[0-9A-Fa-f]{1,4}){0,1}(?:(?::(?:(?:25[0-5]|2[0-4]\d|[01]?\d{1,2})(?:\.(?:25[0-5]|2[0-4]\d|[01]?\d{1,2})){3})?)|(?:(?::[0-9A-Fa-f]{1,4}){1,2})))|(?:(?:[0-9A-Fa-f]{1,4}:){3}(?::[0-9A-Fa-f]{1,4}){0,2}(?:(?::(?:(?:25[0-5]|2[0-4]\d|[01]?\d{1,2})(?:\.(?:25[0-5]|2[0-4]\d|[01]?\d{1,2})){3})?)|(?:(?::[0-9A-Fa-f]{1,4}){1,2})))|(?:(?:[0-9A-Fa-f]{1,4}:){2}(?::[0-9A-Fa-f]{1,4}){0,3}(?:(?::(?:(?:25[0-5]|2[0-4]\d|[01]?\d{1,2})(?:\.(?:25[0-5]|2[0-4]\d|[01]?\d{1,2})){3})?)|(?:(?::[0-9A-Fa-f]{1,4}){1,2})))|(?:(?:[0-9A-Fa-f]{1,4}:)(?::[0-9A-Fa-f]{1,4}){0,4}(?:(?::(?:(?:25[0-5]|2[0-4]\d|[01]?\d{1,2})(?:\.(?:25[0-5]|2[0-4]\d|[01]?\d{1,2})){3})?)|(?:(?::[0-9A-Fa-f]{1,4}){1,2})))|(?::(?::[0-9A-Fa-f]{1,4}){0,5}(?:(?::(?:(?:25[0-5]|2[0-4]\d|[01]?\d{1,2})(?:\.(?:25[0-5]|2[0-4]\d|[01]?\d{1,2})){3})?)|(?:(?::[0-9A-Fa-f]{1,4}){1,2})))|(?:(?:(?:25[0-5]|2[0-4]\d|[01]?\d{1,2})(?:\.(?:25[0-5]|2[0-4]\d|[01]?\d{1,2})){3})))\]?'.
            ')|(?:'. //DNS
                '(?:[\pN\pL\-\_\+]+(?:\:[\pN\pL\-\_\+]+)?\@)?'. //user:pass@
                '[\pN\pL\-\_]+(?:\.[\pN\pL\-\_]+)*\.'.
                //tld list from http://data.iana.org/TLD/tlds-alpha-by-domain.txt, also added local, loc, and onion
                '(?:AC|AD|AE|AERO|AF|AG|AI|AL|AM|AN|AO|AQ|AR|ARPA|AS|ASIA|AT|AU|AW|AX|AZ|BA|BB|BD|BE|BF|BG|BH|BI|BIZ|BJ|BM|BN|BO|BR|BS|BT|BV|BW|BY|BZ|CA|CAT|CC|CD|CF|CG|CH|CI|CK|CL|CM|CN|CO|COM|COOP|CR|CU|CV|CX|CY|CZ|DE|DJ|DK|DM|DO|DZ|EC|EDU|EE|EG|ER|ES|ET|EU|FI|FJ|FK|FM|FO|FR|GA|GB|GD|GE|GF|GG|GH|GI|GL|GM|GN|GOV|GP|GQ|GR|GS|GT|GU|GW|GY|HK|HM|HN|HR|HT|HU|ID|IE|IL|IM|IN|INFO|INT|IO|IQ|IR|IS|IT|JE|JM|JO|JOBS|JP|KE|KG|KH|KI|KM|KN|KP|KR|KW|KY|KZ|LA|LB|LC|LI|LK|LR|LS|LT|LU|LV|LY|MA|MC|MD|ME|MG|MH|MIL|MK|ML|MM|MN|MO|MOBI|MP|MQ|MR|MS|MT|MU|MUSEUM|MV|MW|MX|MY|MZ|NA|NAME|NC|NE|NET|NF|NG|NI|NL|NO|NP|NR|NU|NZ|OM|ORG|PA|PE|PF|PG|PH|PK|PL|PM|PN|PR|PRO|PS|PT|PW|PY|QA|RE|RO|RS|RU|RW|SA|SB|SC|SD|SE|SG|SH|SI|SJ|SK|SL|SM|SN|SO|SR|ST|SU|SV|SY|SZ|TC|TD|TEL|TF|TG|TH|TJ|TK|TL|TM|TN|TO|TP|TR|TRAVEL|TT|TV|TW|TZ|UA|UG|UK|US|UY|UZ|VA|VC|VE|VG|VI|VN|VU|WF|WS|XN--0ZWM56D|测试|XN--11B5BS3A9AJ6G|परीक्षा|XN--80AKHBYKNJ4F|испытание|XN--9T4B11YI5A|테스트|XN--DEBA0AD|טעסט|XN--G6W251D|測試|XN--HGBK6AJ7F53BBA|آزمایشی|XN--HLCJ6AYA9ESC7A|பரிட்சை|XN--JXALPDLP|δοκιμή|XN--KGBECHTV|إختبار|XN--ZCKZAH|テスト|YE|YT|YU|ZA|ZM|ZW|local|loc|onion)'.
            ')(?![\pN\pL\-\_])'.
        ')'.
        '(?:'.
            '(?:\:\d+)?'. //:port
            '(?:/[\pN\pL$\[\]\,\!\(\)\.\-\_\+\/\=\&\;]*)?'. // /path
            '(?:\?[\pN\pL\$\[\]\,\!\(\)\.\-\_\+\/\=\&\;\/]*)?'. // ?query string
            '(?:\#[\pN\pL$\[\]\,\!\(\)\.\-\_\+\/\=\&\;\/\?\#]*)?'. // #fragment
        ')(?<![\?\.\,\#\,])'.
    ')'.
    '#ixu';
    preg_match_all($regex,$text,$matches);
    //print_r($matches);
    return preg_replace_callback($regex, curry('callback_helper',$callback,$notice_id) ,$text);
}

function callback_helper($matches, $callback, $notice_id) {
    $url=$matches[1];
    $left = strpos($matches[0],$url);
    $right = $left+strlen($url);

    $groupSymbolSets=array(
        array(
            'left'=>'(',
            'right'=>')'
        ),
        array(
            'left'=>'[',
            'right'=>']'
        ),
        array(
            'left'=>'{',
            'right'=>'}'
        )
    );
    $cannotEndWith=array('.','?',',','#');
    $original_url=$url;
    do{
        $original_url=$url;
        foreach($groupSymbolSets as $groupSymbolSet){
            if(substr($url,-1)==$groupSymbolSet['right']){
                $group_left_count = substr_count($url,$groupSymbolSet['left']);
                $group_right_count = substr_count($url,$groupSymbolSet['right']);
                if($group_left_count<$group_right_count){
                    $right-=1;
                    $url=substr($url,0,-1);
                }
            }
        }
        if(in_array(substr($url,-1),$cannotEndWith)){
            $right-=1;
            $url=substr($url,0,-1);
        }
    }while($original_url!=$url);

    if(empty($notice_id)){
        $result = call_user_func_array($callback,$url);
    }else{
        $result = call_user_func_array($callback, array($url,$notice_id) );
    }
    return substr($matches[0],0,$left) . $result . substr($matches[0],$right);
}

function curry($fn) {
    //TODO switch to a PHP 5.3 function closure based approach if PHP 5.3 is used
    $args = func_get_args();
    array_shift($args);
    $id = uniqid('_partial');
    $GLOBALS[$id] = array($fn, $args);
    return create_function('',
                           '$args = func_get_args(); '.
                           'return call_user_func_array('.
                           '$GLOBALS["'.$id.'"][0],'.
                           'array_merge('.
                           '$args,'.
                           '$GLOBALS["'.$id.'"][1]));');
}

function common_linkify($url) {
    // It comes in special'd, so we unspecial it before passing to the stringifying
    // functions
    $url = htmlspecialchars_decode($url);

   if(strpos($url, '@') !== false && strpos($url, ':') === false) {
       //url is an email address without the mailto: protocol
       return XMLStringer::estring('a', array('href' => "mailto:$url", 'rel' => 'external'), $url);
   }

    $canon = File_redirection::_canonUrl($url);

    $longurl_data = File_redirection::where($url);
    if (is_array($longurl_data)) {
        $longurl = $longurl_data['url'];
    } elseif (is_string($longurl_data)) {
        $longurl = $longurl_data;
    } else {
        throw new ServerException("Can't linkify url '$url'");
    }

    $attrs = array('href' => $canon, 'rel' => 'external');

    $is_attachment = false;
    $attachment_id = null;
    $has_thumb = false;

    // Check to see whether this is a known "attachment" URL.

    $f = File::staticGet('url', $longurl);

    if (empty($f)) {
        // XXX: this writes to the database. :<
        $f = File::processNew($longurl);
    }

    if (!empty($f)) {
        if (isset($f->filename)) {
            $is_attachment = true;
            $attachment_id = $f->id;
        } else { // if it has OEmbed info, it's an attachment, too
            $foe = File_oembed::staticGet('file_id', $f->id);
            if (!empty($foe)) {
                $is_attachment = true;
                $attachment_id = $f->id;

                $thumb = File_thumbnail::staticGet('file_id', $f->id);
                if (!empty($thumb)) {
                    $has_thumb = true;
                }
            }
        }
    }

    // Add clippy
    if ($is_attachment) {
        $attrs['class'] = 'attachment';
        if ($has_thumb) {
            $attrs['class'] = 'attachment thumbnail';
        }
        $attrs['id'] = "attachment-{$attachment_id}";
    }

    return XMLStringer::estring('a', $attrs, $url);
}

function common_shorten_links($text)
{
    if (mb_strlen($text) <= 140) return $text;
    return common_replace_urls_callback($text, array('File_redirection', 'makeShort'));
}

function common_xml_safe_str($str)
{
    // Neutralize control codes and surrogates
	return preg_replace('/[\p{Cc}\p{Cs}]/u', '*', $str);
}

function common_tag_link($tag)
{
    $canonical = common_canonical_tag($tag);
    $url = common_local_url('tag', array('tag' => $canonical));
    $xs = new XMLStringer();
    $xs->elementStart('span', 'tag');
    $xs->element('a', array('href' => $url,
                            'rel' => 'tag'),
                 $tag);
    $xs->elementEnd('span');
    return $xs->getString();
}

function common_canonical_tag($tag)
{
  $tag = mb_convert_case($tag, MB_CASE_LOWER, "UTF-8");
  return str_replace(array('-', '_', '.'), '', $tag);
}

function common_valid_profile_tag($str)
{
    return preg_match('/^[A-Za-z0-9_\-\.]{1,64}$/', $str);
}

function common_at_link($sender_id, $nickname)
{
    $sender = Profile::staticGet($sender_id);
    $recipient = common_relative_profile($sender, common_canonical_nickname($nickname));
    if ($recipient) {
        $user = User::staticGet('id', $recipient->id);
        if ($user) {
            $url = common_local_url('userbyid', array('id' => $user->id));
        } else {
            $url = $recipient->profileurl;
        }
        $xs = new XMLStringer(false);
        $attrs = array('href' => $url,
                       'class' => 'url');
        if (!empty($recipient->fullname)) {
            $attrs['title'] = $recipient->fullname . ' (' . $recipient->nickname . ')';
        }
        $xs->elementStart('span', 'vcard');
        $xs->elementStart('a', $attrs);
        $xs->element('span', 'fn nickname', $nickname);
        $xs->elementEnd('a');
        $xs->elementEnd('span');
        return $xs->getString();
    } else {
        return $nickname;
    }
}

function common_group_link($sender_id, $nickname)
{
    $sender = Profile::staticGet($sender_id);
    $group = User_group::getForNickname($nickname);
    if ($group && $sender->isMember($group)) {
        $attrs = array('href' => $group->permalink(),
                       'class' => 'url');
        if (!empty($group->fullname)) {
            $attrs['title'] = $group->fullname . ' (' . $group->nickname . ')';
        }
        $xs = new XMLStringer();
        $xs->elementStart('span', 'vcard');
        $xs->elementStart('a', $attrs);
        $xs->element('span', 'fn nickname', $nickname);
        $xs->elementEnd('a');
        $xs->elementEnd('span');
        return $xs->getString();
    } else {
        return $nickname;
    }
}

function common_at_hash_link($sender_id, $tag)
{
    $user = User::staticGet($sender_id);
    if (!$user) {
        return $tag;
    }
    $tagged = Profile_tag::getTagged($user->id, common_canonical_tag($tag));
    if ($tagged) {
        $url = common_local_url('subscriptions',
                                array('nickname' => $user->nickname,
                                      'tag' => $tag));
        $xs = new XMLStringer();
        $xs->elementStart('span', 'tag');
        $xs->element('a', array('href' => $url,
                                'rel' => $tag),
                     $tag);
        $xs->elementEnd('span');
        return $xs->getString();
    } else {
        return $tag;
    }
}

function common_relative_profile($sender, $nickname, $dt=null)
{
    // Try to find profiles this profile is subscribed to that have this nickname
    $recipient = new Profile();
    // XXX: use a join instead of a subquery
    $recipient->whereAdd('EXISTS (SELECT subscribed from subscription where subscriber = '.$sender->id.' and subscribed = id)', 'AND');
    $recipient->whereAdd("nickname = '" . trim($nickname) . "'", 'AND');
    if ($recipient->find(true)) {
        // XXX: should probably differentiate between profiles with
        // the same name by date of most recent update
        return $recipient;
    }
    // Try to find profiles that listen to this profile and that have this nickname
    $recipient = new Profile();
    // XXX: use a join instead of a subquery
    $recipient->whereAdd('EXISTS (SELECT subscriber from subscription where subscribed = '.$sender->id.' and subscriber = id)', 'AND');
    $recipient->whereAdd("nickname = '" . trim($nickname) . "'", 'AND');
    if ($recipient->find(true)) {
        // XXX: should probably differentiate between profiles with
        // the same name by date of most recent update
        return $recipient;
    }
    // If this is a local user, try to find a local user with that nickname.
    $sender = User::staticGet($sender->id);
    if ($sender) {
        $recipient_user = User::staticGet('nickname', $nickname);
        if ($recipient_user) {
            return $recipient_user->getProfile();
        }
    }
    // Otherwise, no links. @messages from local users to remote users,
    // or from remote users to other remote users, are just
    // outside our ability to make intelligent guesses about
    return null;
}

function common_local_url($action, $args=null, $params=null, $fragment=null)
{
    static $sensitive = array('login', 'register', 'passwordsettings',
                              'twittersettings', 'finishopenidlogin',
                              'finishaddopenid', 'api');

    $r = Router::get();
    $path = $r->build($action, $args, $params, $fragment);

    $ssl = in_array($action, $sensitive);

    if (common_config('site','fancy')) {
        $url = common_path(mb_substr($path, 1), $ssl);
    } else {
        if (mb_strpos($path, '/index.php') === 0) {
            $url = common_path(mb_substr($path, 1), $ssl);
        } else {
            $url = common_path('index.php'.$path, $ssl);
        }
    }
    return $url;
}

function common_path($relative, $ssl=false)
{
    $pathpart = (common_config('site', 'path')) ? common_config('site', 'path')."/" : '';

    if (($ssl && (common_config('site', 'ssl') === 'sometimes'))
        || common_config('site', 'ssl') === 'always') {
        $proto = 'https';
        if (is_string(common_config('site', 'sslserver')) &&
            mb_strlen(common_config('site', 'sslserver')) > 0) {
            $serverpart = common_config('site', 'sslserver');
        } else {
            $serverpart = common_config('site', 'server');
        }
    } else {
        $proto = 'http';
        $serverpart = common_config('site', 'server');
    }

    return $proto.'://'.$serverpart.'/'.$pathpart.$relative;
}

function common_date_string($dt)
{
    // XXX: do some sexy date formatting
    // return date(DATE_RFC822, $dt);
    $t = strtotime($dt);
    $now = time();
    $diff = $now - $t;

    if ($now < $t) { // that shouldn't happen!
        return common_exact_date($dt);
    } else if ($diff < 60) {
        return _('a few seconds ago');
    } else if ($diff < 92) {
        return _('about a minute ago');
    } else if ($diff < 3300) {
        return sprintf(_('about %d minutes ago'), round($diff/60));
    } else if ($diff < 5400) {
        return _('about an hour ago');
    } else if ($diff < 22 * 3600) {
        return sprintf(_('about %d hours ago'), round($diff/3600));
    } else if ($diff < 37 * 3600) {
        return _('about a day ago');
    } else if ($diff < 24 * 24 * 3600) {
        return sprintf(_('about %d days ago'), round($diff/(24*3600)));
    } else if ($diff < 46 * 24 * 3600) {
        return _('about a month ago');
    } else if ($diff < 330 * 24 * 3600) {
        return sprintf(_('about %d months ago'), round($diff/(30*24*3600)));
    } else if ($diff < 480 * 24 * 3600) {
        return _('about a year ago');
    } else {
        return common_exact_date($dt);
    }
}

function common_exact_date($dt)
{
    static $_utc;
    static $_siteTz;

    if (!$_utc) {
        $_utc = new DateTimeZone('UTC');
        $_siteTz = new DateTimeZone(common_timezone());
    }

    $dateStr = date('d F Y H:i:s', strtotime($dt));
    $d = new DateTime($dateStr, $_utc);
    $d->setTimezone($_siteTz);
    return $d->format(DATE_RFC850);
}

function common_date_w3dtf($dt)
{
    $dateStr = date('d F Y H:i:s', strtotime($dt));
    $d = new DateTime($dateStr, new DateTimeZone('UTC'));
    $d->setTimezone(new DateTimeZone(common_timezone()));
    return $d->format(DATE_W3C);
}

function common_date_rfc2822($dt)
{
    $dateStr = date('d F Y H:i:s', strtotime($dt));
    $d = new DateTime($dateStr, new DateTimeZone('UTC'));
    $d->setTimezone(new DateTimeZone(common_timezone()));
    return $d->format('r');
}

function common_date_iso8601($dt)
{
    $dateStr = date('d F Y H:i:s', strtotime($dt));
    $d = new DateTime($dateStr, new DateTimeZone('UTC'));
    $d->setTimezone(new DateTimeZone(common_timezone()));
    return $d->format('c');
}

function common_sql_now()
{
    return common_sql_date(time());
}

function common_sql_date($datetime)
{
    return strftime('%Y-%m-%d %H:%M:%S', $datetime);
}

function common_redirect($url, $code=307)
{
    static $status = array(301 => "Moved Permanently",
                           302 => "Found",
                           303 => "See Other",
                           307 => "Temporary Redirect");

    header('HTTP/1.1 '.$code.' '.$status[$code]);
    header("Location: $url");

    $xo = new XMLOutputter();
    $xo->startXML('a',
                  '-//W3C//DTD XHTML 1.0 Strict//EN',
                  'http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd');
    $xo->element('a', array('href' => $url), $url);
    $xo->endXML();
    exit;
}

function common_broadcast_notice($notice, $remote=false)
{
    return common_enqueue_notice($notice);
}

// Stick the notice on the queue

function common_enqueue_notice($notice)
{
    static $localTransports = array('omb',
                                    'twitter',
                                    'facebook',
                                    'ping');
    static $allTransports = array('sms');

    $transports = $allTransports;

    $xmpp = common_config('xmpp', 'enabled');

    if ($xmpp) {
        $transports[] = 'jabber';
    }

    if ($notice->is_local == Notice::LOCAL_PUBLIC ||
        $notice->is_local == Notice::LOCAL_NONPUBLIC) {
        $transports = array_merge($transports, $localTransports);
        if ($xmpp) {
            $transports[] = 'public';
        }
    }

    $qm = QueueManager::get();

    foreach ($transports as $transport)
    {
        $qm->enqueue($notice, $transport);
    }

    return true;
}

function common_broadcast_profile($profile)
{
    // XXX: optionally use a queue system like http://code.google.com/p/microapps/wiki/NQDQ
    require_once(INSTALLDIR.'/lib/omb.php');
    omb_broadcast_profile($profile);
    // XXX: Other broadcasts...?
    return true;
}

function common_profile_url($nickname)
{
    return common_local_url('showstream', array('nickname' => $nickname));
}

// Should make up a reasonable root URL

function common_root_url($ssl=false)
{
    return common_path('', $ssl);
}

// returns $bytes bytes of random data as a hexadecimal string
// "good" here is a goal and not a guarantee

function common_good_rand($bytes)
{
    // XXX: use random.org...?
    if (@file_exists('/dev/urandom')) {
        return common_urandom($bytes);
    } else { // FIXME: this is probably not good enough
        return common_mtrand($bytes);
    }
}

function common_urandom($bytes)
{
    $h = fopen('/dev/urandom', 'rb');
    // should not block
    $src = fread($h, $bytes);
    fclose($h);
    $enc = '';
    for ($i = 0; $i < $bytes; $i++) {
        $enc .= sprintf("%02x", (ord($src[$i])));
    }
    return $enc;
}

function common_mtrand($bytes)
{
    $enc = '';
    for ($i = 0; $i < $bytes; $i++) {
        $enc .= sprintf("%02x", mt_rand(0, 255));
    }
    return $enc;
}

function common_set_returnto($url)
{
    common_ensure_session();
    $_SESSION['returnto'] = $url;
}

function common_get_returnto()
{
    common_ensure_session();
    return $_SESSION['returnto'];
}

function common_timestamp()
{
    return date('YmdHis');
}

function common_ensure_syslog()
{
    static $initialized = false;
    if (!$initialized) {
        openlog(common_config('syslog', 'appname'), 0,
            common_config('syslog', 'facility'));
        $initialized = true;
    }
}

function common_log_line($priority, $msg)
{
    static $syslog_priorities = array('LOG_EMERG', 'LOG_ALERT', 'LOG_CRIT', 'LOG_ERR',
                                      'LOG_WARNING', 'LOG_NOTICE', 'LOG_INFO', 'LOG_DEBUG');
    return date('Y-m-d H:i:s') . ' ' . $syslog_priorities[$priority] . ': ' . $msg . "\n";
}

function common_log($priority, $msg, $filename=null)
{
    $logfile = common_config('site', 'logfile');
    if ($logfile) {
        $log = fopen($logfile, "a");
        if ($log) {
            $output = common_log_line($priority, $msg);
            fwrite($log, $output);
            fclose($log);
        }
    } else {
        common_ensure_syslog();
        syslog($priority, $msg);
    }
}

function common_debug($msg, $filename=null)
{
    if ($filename) {
        common_log(LOG_DEBUG, basename($filename).' - '.$msg);
    } else {
        common_log(LOG_DEBUG, $msg);
    }
}

function common_log_db_error(&$object, $verb, $filename=null)
{
    $objstr = common_log_objstring($object);
    $last_error = &PEAR::getStaticProperty('DB_DataObject','lastError');
    common_log(LOG_ERR, $last_error->message . '(' . $verb . ' on ' . $objstr . ')', $filename);
}

function common_log_objstring(&$object)
{
    if (is_null($object)) {
        return "null";
    }
    if (!($object instanceof DB_DataObject)) {
        return "(unknown)";
    }
    $arr = $object->toArray();
    $fields = array();
    foreach ($arr as $k => $v) {
        $fields[] = "$k='$v'";
    }
    $objstring = $object->tableName() . '[' . implode(',', $fields) . ']';
    return $objstring;
}

function common_valid_http_url($url)
{
    return Validate::uri($url, array('allowed_schemes' => array('http', 'https')));
}

function common_valid_tag($tag)
{
    if (preg_match('/^tag:(.*?),(\d{4}(-\d{2}(-\d{2})?)?):(.*)$/', $tag, $matches)) {
        return (Validate::email($matches[1]) ||
                preg_match('/^([\w-\.]+)$/', $matches[1]));
    }
    return false;
}

/* Following functions are copied from MediaWiki GlobalFunctions.php
 * and written by Evan Prodromou. */

function common_accept_to_prefs($accept, $def = '*/*')
{
    // No arg means accept anything (per HTTP spec)
    if(!$accept) {
        return array($def => 1);
    }

    $prefs = array();

    $parts = explode(',', $accept);

    foreach($parts as $part) {
        // FIXME: doesn't deal with params like 'text/html; level=1'
        @list($value, $qpart) = explode(';', trim($part));
        $match = array();
        if(!isset($qpart)) {
            $prefs[$value] = 1;
        } elseif(preg_match('/q\s*=\s*(\d*\.\d+)/', $qpart, $match)) {
            $prefs[$value] = $match[1];
        }
    }

    return $prefs;
}

function common_mime_type_match($type, $avail)
{
    if(array_key_exists($type, $avail)) {
        return $type;
    } else {
        $parts = explode('/', $type);
        if(array_key_exists($parts[0] . '/*', $avail)) {
            return $parts[0] . '/*';
        } elseif(array_key_exists('*/*', $avail)) {
            return '*/*';
        } else {
            return null;
        }
    }
}

function common_negotiate_type($cprefs, $sprefs)
{
    $combine = array();

    foreach(array_keys($sprefs) as $type) {
        $parts = explode('/', $type);
        if($parts[1] != '*') {
            $ckey = common_mime_type_match($type, $cprefs);
            if($ckey) {
                $combine[$type] = $sprefs[$type] * $cprefs[$ckey];
            }
        }
    }

    foreach(array_keys($cprefs) as $type) {
        $parts = explode('/', $type);
        if($parts[1] != '*' && !array_key_exists($type, $sprefs)) {
            $skey = common_mime_type_match($type, $sprefs);
            if($skey) {
                $combine[$type] = $sprefs[$skey] * $cprefs[$type];
            }
        }
    }

    $bestq = 0;
    $besttype = 'text/html';

    foreach(array_keys($combine) as $type) {
        if($combine[$type] > $bestq) {
            $besttype = $type;
            $bestq = $combine[$type];
        }
    }

    if ('text/html' === $besttype) {
        return "text/html; charset=utf-8";
    }
    return $besttype;
}

function common_config($main, $sub)
{
    global $config;
    return isset($config[$main][$sub]) ? $config[$main][$sub] : false;
}

function common_copy_args($from)
{
    $to = array();
    $strip = get_magic_quotes_gpc();
    foreach ($from as $k => $v) {
        $to[$k] = ($strip) ? stripslashes($v) : $v;
    }
    return $to;
}

// Neutralise the evil effects of magic_quotes_gpc in the current request.
// This is used before handing a request off to OAuthRequest::from_request.
function common_remove_magic_from_request()
{
    if(get_magic_quotes_gpc()) {
        $_POST=array_map('stripslashes',$_POST);
        $_GET=array_map('stripslashes',$_GET);
    }
}

function common_user_uri(&$user)
{
    return common_local_url('userbyid', array('id' => $user->id));
}

function common_notice_uri(&$notice)
{
    return common_local_url('shownotice',
                            array('notice' => $notice->id));
}

// 36 alphanums - lookalikes (0, O, 1, I) = 32 chars = 5 bits

function common_confirmation_code($bits)
{
    // 36 alphanums - lookalikes (0, O, 1, I) = 32 chars = 5 bits
    static $codechars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $chars = ceil($bits/5);
    $code = '';
    for ($i = 0; $i < $chars; $i++) {
        // XXX: convert to string and back
        $num = hexdec(common_good_rand(1));
        // XXX: randomness is too precious to throw away almost
        // 40% of the bits we get!
        $code .= $codechars[$num%32];
    }
    return $code;
}

// convert markup to HTML

function common_markup_to_html($c)
{
    $c = preg_replace('/%%action.(\w+)%%/e', "common_local_url('\\1')", $c);
    $c = preg_replace('/%%doc.(\w+)%%/e', "common_local_url('doc', array('title'=>'\\1'))", $c);
    $c = preg_replace('/%%(\w+).(\w+)%%/e', 'common_config(\'\\1\', \'\\2\')', $c);
    return Markdown($c);
}

function common_profile_uri($profile)
{
    if (!$profile) {
        return null;
    }
    $user = User::staticGet($profile->id);
    if ($user) {
        return $user->uri;
    }

    $remote = Remote_profile::staticGet($profile->id);
    if ($remote) {
        return $remote->uri;
    }
    // XXX: this is a very bad profile!
    return null;
}

function common_canonical_sms($sms)
{
    // strip non-digits
    preg_replace('/\D/', '', $sms);
    return $sms;
}

function common_error_handler($errno, $errstr, $errfile, $errline, $errcontext)
{
    switch ($errno) {

     case E_ERROR:
     case E_COMPILE_ERROR:
     case E_CORE_ERROR:
     case E_USER_ERROR:
     case E_PARSE:
     case E_RECOVERABLE_ERROR:
        common_log(LOG_ERR, "[$errno] $errstr ($errfile:$errline) [ABORT]");
        die();
        break;

     case E_WARNING:
     case E_COMPILE_WARNING:
     case E_CORE_WARNING:
     case E_USER_WARNING:
        common_log(LOG_WARNING, "[$errno] $errstr ($errfile:$errline)");
        break;

     case E_NOTICE:
     case E_USER_NOTICE:
        common_log(LOG_NOTICE, "[$errno] $errstr ($errfile:$errline)");
        break;

     case E_STRICT:
     case E_DEPRECATED:
     case E_USER_DEPRECATED:
        // XXX: config variable to log this stuff, too
        break;

     default:
        common_log(LOG_ERR, "[$errno] $errstr ($errfile:$errline) [UNKNOWN LEVEL, die()'ing]");
        die();
        break;
    }

    // FIXME: show error page if we're on the Web
    /* Don't execute PHP internal error handler */
    return true;
}

function common_session_token()
{
    common_ensure_session();
    if (!array_key_exists('token', $_SESSION)) {
        $_SESSION['token'] = common_good_rand(64);
    }
    return $_SESSION['token'];
}

function common_cache_key($extra)
{
    $base_key = common_config('memcached', 'base');

    if (empty($base_key)) {
        $base_key = common_keyize(common_config('site', 'name'));
    }

    return 'statusnet:' . $base_key . ':' . $extra;
}

function common_keyize($str)
{
    $str = strtolower($str);
    $str = preg_replace('/\s/', '_', $str);
    return $str;
}

function common_memcache()
{
    static $cache = null;
    if (!common_config('memcached', 'enabled')) {
        return null;
    } else {
        if (!$cache) {
            $cache = new Memcache();
            $servers = common_config('memcached', 'server');
            if (is_array($servers)) {
                foreach($servers as $server) {
                    $cache->addServer($server);
                }
            } else {
                $cache->addServer($servers);
            }
        }
        return $cache;
    }
}

function common_compatible_license($from, $to)
{
    // XXX: better compatibility check needed here!
    return ($from == $to);
}

/**
 * returns a quoted table name, if required according to config
 */
function common_database_tablename($tablename)
{

  if(common_config('db','quote_identifiers')) {
      $tablename = '"'. $tablename .'"';
  }
  //table prefixes could be added here later
  return $tablename;
}

function common_shorten_url($long_url)
{
    $user = common_current_user();
    if (empty($user)) {
        // common current user does not find a user when called from the XMPP daemon
        // therefore we'll set one here fix, so that XMPP given URLs may be shortened
        $svc = 'ur1.ca';
    } else {
        $svc = $user->urlshorteningservice;
    }

    $curlh = curl_init();
    curl_setopt($curlh, CURLOPT_CONNECTTIMEOUT, 20); // # seconds to wait
    curl_setopt($curlh, CURLOPT_USERAGENT, 'StatusNet');
    curl_setopt($curlh, CURLOPT_RETURNTRANSFER, true);

    switch($svc) {
     case 'ur1.ca':
        require_once INSTALLDIR.'/lib/Shorturl_api.php';
        $short_url_service = new LilUrl;
        $short_url = $short_url_service->shorten($long_url);
        break;

     case '2tu.us':
        $short_url_service = new TightUrl;
        require_once INSTALLDIR.'/lib/Shorturl_api.php';
        $short_url = $short_url_service->shorten($long_url);
        break;

     case 'ptiturl.com':
        require_once INSTALLDIR.'/lib/Shorturl_api.php';
        $short_url_service = new PtitUrl;
        $short_url = $short_url_service->shorten($long_url);
        break;

     case 'bit.ly':
        curl_setopt($curlh, CURLOPT_URL, 'http://bit.ly/api?method=shorten&long_url='.urlencode($long_url));
        $short_url = current(json_decode(curl_exec($curlh))->results)->hashUrl;
        break;

     case 'is.gd':
        curl_setopt($curlh, CURLOPT_URL, 'http://is.gd/api.php?longurl='.urlencode($long_url));
        $short_url = curl_exec($curlh);
        break;
     case 'snipr.com':
        curl_setopt($curlh, CURLOPT_URL, 'http://snipr.com/site/snip?r=simple&link='.urlencode($long_url));
        $short_url = curl_exec($curlh);
        break;
     case 'metamark.net':
        curl_setopt($curlh, CURLOPT_URL, 'http://metamark.net/api/rest/simple?long_url='.urlencode($long_url));
        $short_url = curl_exec($curlh);
        break;
     case 'tinyurl.com':
        curl_setopt($curlh, CURLOPT_URL, 'http://tinyurl.com/api-create.php?url='.urlencode($long_url));
        $short_url = curl_exec($curlh);
        break;
     default:
        $short_url = false;
    }

    curl_close($curlh);

    return $short_url;
}

function common_client_ip()
{
    if (!isset($_SERVER) || !array_key_exists('REQUEST_METHOD', $_SERVER)) {
        return null;
    }

    if (array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER)) {
        if (array_key_exists('HTTP_CLIENT_IP', $_SERVER)) {
            $proxy = $_SERVER['HTTP_CLIENT_IP'];
        } else {
            $proxy = $_SERVER['REMOTE_ADDR'];
        }
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        $proxy = null;
        if (array_key_exists('HTTP_CLIENT_IP', $_SERVER)) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
    }

    return array($proxy, $ip);
}

function common_ldap_get_name($campousername, $campopassword,&$fullname) {
  $ldap_server = common_config('ldap', 'server');
  $ldap_basedn = common_config('ldap', 'basedn');
  $ldap_userid = common_config('ldap', 'userid');
  $ldap_coneccion=@ldap_connect($ldap_server,389) or die(ldap_error());
  $ldap_dn=$ldap_userid.'='.$campousername.','.$ldap_basedn;
  $ldap_pwd=$campopassword;
  if(@ldap_bind($ldap_coneccion,$ldap_dn,$ldap_pwd))
  {
    $ldap_criterio=$ldap_userid.'='.$campousername;
    $ldap_retorno=array("cn","employeeNumber","employeeType");
    $ldap_buscar=ldap_search($ldap_coneccion,$ldap_basedn,$ldap_criterio,$ldap_retorno) or die(ldap_error());
    $info = ldap_get_entries($ldap_coneccion, $ldap_buscar);
    $entrada = ldap_first_entry($ldap_coneccion, $ldap_buscar);
    $atributos = ldap_get_attributes($ldap_coneccion, $entrada);
    $valores = ldap_get_values($ldap_coneccion, $entrada,$atributos[0]);
    $fullname = $valores[0];
    @ldap_close($ldap_coneccion);
    return true;
  }
    else
  {
    @ldap_close($ldap_coneccion);
    return false;
  }
}

function common_ldap_check_user($campousername, $campopassword) {
  $ldap_server = common_config('ldap', 'server');
  $ldap_basedn = common_config('ldap', 'basedn');
  $ldap_userid = common_config('ldap', 'userid');
  $ldap_coneccion=@ldap_connect($ldap_server,389) or die(ldap_error());
  $ldap_dn=$ldap_userid.'='.$campousername.','.$ldap_basedn;
  $ldap_pwd=$campopassword;
  if(@ldap_bind($ldap_coneccion,$ldap_dn,$ldap_pwd))
  {
    $ldap_criterio=$ldap_userid.'='.$campousername;
    $ldap_retorno=array("cn","employeeNumber","employeeType");
    $ldap_buscar=ldap_search($ldap_coneccion,$ldap_basedn,$ldap_criterio,$ldap_retorno) or die(ldap_error());
    $info = ldap_get_entries($ldap_coneccion, $ldap_buscar);
    @ldap_close($ldap_coneccion);
    return true;
  }
    else
  {
    @ldap_close($ldap_coneccion);
    return false;
  }
}

