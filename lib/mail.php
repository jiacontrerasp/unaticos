<?php
/**
 * StatusNet, the distributed open-source microblogging tool
 *
 * utilities for sending email
 *
 * PHP version 5
 *
 * LICENCE: This program is free software: you can redistribute it and/or modify
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
 *
 * @category  Mail
 * @package   StatusNet
 * @author    Evan Prodromou <evan@status.net>
 * @author    Zach Copley <zach@status.net>
 * @author    Robin Millette <millette@status.net>
 * @author    Sarven Capadisli <csarven@status.net>
 * @copyright 2008 StatusNet, Inc.
 * @license   http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link      http://status.net/
 */

if (!defined('STATUSNET') && !defined('LACONICA')) {
    exit(1);
}

require_once 'Mail.php';

/**
 * return the configured mail backend
 *
 * Uses the $config array to make a mail backend. Cached so it is safe to call
 * more than once.
 *
 * @return Mail backend
 */

function mail_backend()
{
    static $backend = null;

    if (!$backend) {
        $backend = Mail::factory(common_config('mail', 'backend'),
                                 (common_config('mail', 'params')) ?
                                 common_config('mail', 'params') :
                                 array());
        if (PEAR::isError($backend)) {
            common_server_error($backend->getMessage(), 500);
        }
    }
    return $backend;
}

/**
 * send an email to one or more recipients
 *
 * @param array  $recipients array of strings with email addresses of recipients
 * @param array  $headers    array mapping strings to strings for email headers
 * @param string $body       body of the email
 *
 * @return boolean success flag
 */

function mail_send($recipients, $headers, $body)
{
    // XXX: use Mail_Queue... maybe
    $backend = mail_backend();
    if (!isset($headers['Content-Type'])) {
        $headers['Content-Type'] = 'text/plain; charset=UTF-8';
    }
    assert($backend); // throws an error if it's bad
    $sent = $backend->send($recipients, $headers, $body);
    if (PEAR::isError($sent)) {
        common_log(LOG_ERR, 'Email error: ' . $sent->getMessage());
        return false;
    }
    return true;
}

/**
 * returns the configured mail domain
 *
 * Defaults to the server name.
 *
 * @return string mail domain, suitable for making email addresses.
 */

function mail_domain()
{
    $maildomain = common_config('mail', 'domain');
    if (!$maildomain) {
        $maildomain = common_config('site', 'server');
    }
    return $maildomain;
}

/**
 * returns a good address for sending email from this server
 *
 * Uses either the configured value or a faked-up value made
 * from the mail domain.
 *
 * @return string notify from address
 */

function mail_notify_from()
{
    $notifyfrom = common_config('mail', 'notifyfrom');

    if (!$notifyfrom) {

        $domain = mail_domain();

        $notifyfrom = '"'.common_config('site', 'name') .'" <noreply@'.$domain.'>';
    }

    return $notifyfrom;
}

/**
 * sends email to a user
 *
 * @param User   &$user   user to send email to
 * @param string $subject subject of the email
 * @param string $body    body of the email
 * @param string $address optional specification of email address
 *
 * @return boolean success flag
 */

function mail_to_user(&$user, $subject, $body, $address=null)
{
    if (!$address) {
        $address = $user->email;
    }

    $recipients = $address;
    $profile    = $user->getProfile();

    $headers['From']    = mail_notify_from();
    $headers['To']      = $profile->getBestName() . ' <' . $address . '>';
    $headers['Subject'] = $subject;

    return mail_send($recipients, $headers, $body);
}

/**
 * Send an email to confirm a user's control of an email address
 *
 * @param User   $user     User claiming the email address
 * @param string $code     Confirmation code
 * @param string $nickname Nickname of user
 * @param string $address  email address to confirm
 *
 * @see common_confirmation_code()
 *
 * @return success flag
 */

function mail_confirm_address($user, $code, $nickname, $address)
{
    $subject = _('Bienvenid@ a UNatiCoS');

    $body = sprintf(_("Hola, %s.\n\n".
                      "Bienvenid@ a %s.\n\n" .
                      "UNatiCoS es un MicroBlogging el cual es un servicio\n" .
		      "que permite a sus usuarios enviar y publicar mensajes\n" .
		      "breves (alrededor de 140 caracteres), generalmente de \n" .
		      "sólo texto. Las opciones para el envío de los mensajes \n" .
		      "varían desde sitios web, a través de SMS, mensajería\n" .
		      "instantánea o aplicaciones ad hoc. \n\n" .
                      "Por favor confirme su cuenta en el siguiente link: \n\n\t%s\n\n" .
                      "Al ingresar a UNatiCoS está aceptando los terminos de uso:\n\n".
		      "\t http://disi.unal.edu.co/unaticos/doc/tos\n\n" .
                      "Gracias por su tiempo, \nEl equipo %s\n"),
                    $nickname, common_config('site', 'name'),
                    common_local_url('confirmaddress', array('code' => $code)),
                    common_config('site', 'name'));
    return mail_to_user($user, $subject, $body, $address);
}

/**
 * notify a user of subscription by another user
 *
 * This is just a wrapper around the profile-based version.
 *
 * @param User $listenee user who is being subscribed to
 * @param User $listener user who is subscribing
 *
 * @see mail_subscribe_notify_profile()
 *
 * @return void
 */

function mail_subscribe_notify($listenee, $listener)
{
    $other = $listener->getProfile();
    mail_subscribe_notify_profile($listenee, $other);
}

/**
 * notify a user of subscription by a profile (remote or local)
 *
 * This function checks to see if the listenee has an email
 * address and wants subscription notices.
 *
 * @param User    $listenee user who's being subscribed to
 * @param Profile $other    profile of person who's listening
 *
 * @return void
 */

function mail_subscribe_notify_profile($listenee, $other)
{
    if ($listenee->email && $listenee->emailnotifysub) {

        // use the recipient's localization
        common_init_locale($listenee->language);

        $profile = $listenee->getProfile();

        $name = $profile->getBestName();

        $long_name = ($other->fullname) ?
          ($other->fullname . ' (' . $other->nickname . ')') : $other->nickname;

        $recipients = $listenee->email;

        $headers['From']    = mail_notify_from();
        $headers['To']      = $name . ' <' . $listenee->email . '>';
        $headers['Subject'] = sprintf(_('%1$s is now listening to '.
                                        'your notices on %2$s.'),
                                      $other->getBestName(),
                                      common_config('site', 'name'));

        $body = sprintf(_('%1$s is now listening to your notices on %2$s.'."\n\n".
                          "\t".'%3$s'."\n\n".
                          '%4$s'.
                          '%5$s'.
                          '%6$s'.
                          "\n".'Faithfully yours,'."\n".'%7$s.'."\n\n".
                          "----\n".
                          "Change your email address or ".
                          "notification options at ".'%8$s' ."\n"),
                        $long_name,
                        common_config('site', 'name'),
                        $other->profileurl,
                        ($other->location) ?
                        sprintf(_("Location: %s\n"), $other->location) : '',
                        ($other->homepage) ?
                        sprintf(_("Homepage: %s\n"), $other->homepage) : '',
                        ($other->bio) ?
                        sprintf(_("Bio: %s\n\n"), $other->bio) : '',
                        common_config('site', 'name'),
                        common_local_url('emailsettings'));

        // reset localization
        common_init_locale();
        mail_send($recipients, $headers, $body);
    }
}

/**
 * notify a user of their new incoming email address
 *
 * User's email and incoming fields should already be updated.
 *
 * @param User $user user with the new address
 *
 * @return void
 */

function mail_new_incoming_notify($user)
{
    $profile = $user->getProfile();

    $name = $profile->getBestName();

    $headers['From']    = $user->incomingemail;
    $headers['To']      = $name . ' <' . $user->email . '>';
    $headers['Subject'] = sprintf(_('New email address for posting to %s'),
                                  common_config('site', 'name'));

    $body = sprintf(_("You have a new posting address on %1\$s.\n\n".
                      "Send email to %2\$s to post new messages.\n\n".
                      "More email instructions at %3\$s.\n\n".
                      "Faithfully yours,\n%4\$s"),
                    common_config('site', 'name'),
                    $user->incomingemail,
                    common_local_url('doc', array('title' => 'email')),
                    common_config('site', 'name'));

    mail_send($user->email, $headers, $body);
}

/**
 * generate a new address for incoming messages
 *
 * @todo check the database for uniqueness
 *
 * @return string new email address for incoming messages
 */

function mail_new_incoming_address()
{
    $prefix = common_confirmation_code(64);
    $suffix = mail_domain();
    return $prefix . '@' . $suffix;
}

/**
 * broadcast a notice to all subscribers with SMS notification on
 *
 * This function sends SMS messages to all users who have sms addresses;
 * have sms notification on; and have sms enabled for this particular
 * subscription.
 *
 * @param Notice $notice The notice to broadcast
 *
 * @return success flag
 */

function mail_broadcast_notice_sms($notice)
{
    // Now, get users subscribed to this profile

    $user = new User();

    $UT = common_config('db','type')=='pgsql'?'"user"':'user';
    $user->query('SELECT nickname, smsemail, incomingemail ' .
                 "FROM $UT JOIN subscription " .
                 "ON $UT.id = subscription.subscriber " .
                 'WHERE subscription.subscribed = ' . $notice->profile_id . ' ' .
                 'AND subscription.subscribed != subscription.subscriber ' .
                 "AND $UT.smsemail IS NOT null " .
                 "AND $UT.smsnotify = 1 " .
                 'AND subscription.sms = 1 ');

    while ($user->fetch()) {
        common_log(LOG_INFO,
                   'Sending notice ' . $notice->id . ' to ' . $user->smsemail,
                   __FILE__);
        $success = mail_send_sms_notice_address($notice,
                                                $user->smsemail,
                                                $user->incomingemail);
        if (!$success) {
            // XXX: Not sure, but I think that's the right thing to do
            common_log(LOG_WARNING,
                       'Sending notice ' . $notice->id . ' to ' .
                       $user->smsemail . ' FAILED, cancelling.',
                       __FILE__);
            return false;
        }
    }

    $user->free();
    unset($user);

    return true;
}

/**
 * send a notice to a user via SMS
 *
 * A convenience wrapper around mail_send_sms_notice_address()
 *
 * @param Notice $notice notice to send
 * @param User   $user   user to receive notice
 *
 * @see mail_send_sms_notice_address()
 *
 * @return boolean success flag
 */

function mail_send_sms_notice($notice, $user)
{
    return mail_send_sms_notice_address($notice,
                                        $user->smsemail,
                                        $user->incomingemail);
}

/**
 * send a notice to an SMS email address from a given address
 *
 * We use the user's incoming email address as the "From" address to make
 * replying to notices easier.
 *
 * @param Notice $notice        notice to send
 * @param string $smsemail      email address to send to
 * @param string $incomingemail email address to set as 'from'
 *
 * @return boolean success flag
 */

function mail_send_sms_notice_address($notice, $smsemail, $incomingemail)
{
    $to = $nickname . ' <' . $smsemail . '>';

    $other = $notice->getProfile();

    common_log(LOG_INFO, 'Sending notice ' . $notice->id .
               ' to ' . $smsemail, __FILE__);

    $headers = array();

    $headers['From']    = ($incomingemail) ? $incomingemail : mail_notify_from();
    $headers['To']      = $to;
    $headers['Subject'] = sprintf(_('%s status'),
                                  $other->getBestName());

    $body = $notice->content;

    return mail_send($smsemail, $headers, $body);
}

/**
 * send a message to confirm a claim for an SMS number
 *
 * @param string $code     confirmation code
 * @param string $nickname nickname of user claiming number
 * @param string $address  email address to send the confirmation to
 *
 * @see common_confirmation_code()
 *
 * @return void
 */

function mail_confirm_sms($code, $nickname, $address)
{
    $recipients = $address;

    $headers['From']    = mail_notify_from();
    $headers['To']      = $nickname . ' <' . $address . '>';
    $headers['Subject'] = _('SMS confirmation');

    // FIXME: I18N

    $body  = "$nickname: confirm you own this phone number with this code:";
    $body .= "\n\n";
    $body .= $code;
    $body .= "\n\n";

    mail_send($recipients, $headers, $body);
}

/**
 * send a mail message to notify a user of a 'nudge'
 *
 * @param User $from user nudging
 * @param User $to   user being nudged
 *
 * @return boolean success flag
 */

function mail_notify_nudge($from, $to)
{
    common_init_locale($to->language);
    $subject = sprintf(_('You\'ve been nudged by %s'), $from->nickname);

    $from_profile = $from->getProfile();

    $body = sprintf(_("%1\$s (%2\$s) is wondering what you are up to ".
                      "these days and is inviting you to post some news.\n\n".
                      "So let's hear from you :)\n\n".
                      "%3\$s\n\n".
                      "Don't reply to this email; it won't get to them.\n\n".
                      "With kind regards,\n".
                      "%4\$s\n"),
                    $from_profile->getBestName(),
                    $from->nickname,
                    common_local_url('all', array('nickname' => $to->nickname)),
                    common_config('site', 'name'));
    common_init_locale();
    return mail_to_user($to, $subject, $body);
}

/**
 * send a message to notify a user of a direct message (DM)
 *
 * This function checks to see if the recipient wants notification
 * of DMs and has a configured email address.
 *
 * @param Message $message message to notify about
 * @param User    $from    user sending message; default to sender
 * @param User    $to      user receiving message; default to recipient
 *
 * @return boolean success code
 */

function mail_notify_message($message, $from=null, $to=null)
{
    if (is_null($from)) {
        $from = User::staticGet('id', $message->from_profile);
    }

    if (is_null($to)) {
        $to = User::staticGet('id', $message->to_profile);
    }

    if (is_null($to->email) || !$to->emailnotifymsg) {
        return true;
    }

    common_init_locale($to->language);
    $subject = sprintf(_('New private message from %s'), $from->nickname);

    $from_profile = $from->getProfile();

    $body = sprintf(_("%1\$s (%2\$s) sent you a private message:\n\n".
                      "------------------------------------------------------\n".
                      "%3\$s\n".
                      "------------------------------------------------------\n\n".
                      "You can reply to their message here:\n\n".
                      "%4\$s\n\n".
                      "Don't reply to this email; it won't get to them.\n\n".
                      "With kind regards,\n".
                      "%5\$s\n"),
                    $from_profile->getBestName(),
                    $from->nickname,
                    $message->content,
                    common_local_url('newmessage', array('to' => $from->id)),
                    common_config('site', 'name'));

    common_init_locale();
    return mail_to_user($to, $subject, $body);
}

/**
 * notify a user that one of their notices has been chosen as a 'fave'
 *
 * Doesn't check that the user has an email address nor if they
 * want to receive notification of faves. Maybe this happens higher
 * up the stack...?
 *
 * @param User   $other  The user whose notice was faved
 * @param User   $user   The user who faved the notice
 * @param Notice $notice The notice that was faved
 *
 * @return void
 */

function mail_notify_fave($other, $user, $notice)
{
    $profile = $user->getProfile();

    $bestname = $profile->getBestName();

    common_init_locale($other->language);

    $subject = sprintf(_('%s added your notice as a favorite'), $bestname);

    $body = sprintf(_("%1\$s just added your notice from %2\$s".
                      " as one of their favorites.\n\n" .
                      "The URL of your notice is:\n\n" .
                      "%3\$s\n\n" .
                      "The text of your notice is:\n\n" .
                      "%4\$s\n\n" .
                      "You can see the list of %1\$s's favorites here:\n\n" .
                      "%5\$s\n\n" .
                      "Faithfully yours,\n" .
                      "%6\$s\n"),
                    $bestname,
                    common_exact_date($notice->created),
                    common_local_url('shownotice',
                                     array('notice' => $notice->id)),
                    $notice->content,
                    common_local_url('showfavorites',
                                     array('nickname' => $user->nickname)),
                    common_config('site', 'name'));

    common_init_locale();
    mail_to_user($other, $subject, $body);
}

/**
 * notify a user that they have received an "attn:" message AKA "@-reply"
 *
 * @param User   $user   The user who recevied the notice
 * @param Notice $notice The notice that was sent
 *
 * @return void
 */

function mail_notify_attn($user, $notice)
{
    if (!$user->email || !$user->emailnotifyattn) {
        return;
    }

    $sender = $notice->getProfile();

    $bestname = $sender->getBestName();

    common_init_locale($user->language);
	
	if ($notice->conversation != $notice->id) {
		$conversationEmailText = "The full conversation can be read here:\n\n".
								 "\t%5\$s\n\n ";
		$conversationUrl 	   = common_local_url('conversation',
                                 array('id' => $notice->conversation)).'#notice-'.$notice->id;
	} else {
		$conversationEmailText = "%5\$s";
		$conversationUrl = null;
	}
	
    $subject = sprintf(_('%s sent a notice to your attention'), $bestname);
	
	$body = sprintf(_("%1\$s just sent a notice to your attention (an '@-reply') on %2\$s.\n\n".
                      "The notice is here:\n\n".
                      "\t%3\$s\n\n" .
                      "It reads:\n\n".
                      "\t%4\$s\n\n" .
                      $conversationEmailText .
                      "You can reply back here:\n\n".
                      "\t%6\$s\n\n" .
                      "The list of all @-replies for you here:\n\n" .
                      "%7\$s\n\n" .
                      "Faithfully yours,\n" .
                      "%2\$s\n\n" .
                      "P.S. You can turn off these email notifications here: %8\$s\n"),
                    $bestname,//%1
                    common_config('site', 'name'),//%2
                    common_local_url('shownotice',
                                     array('notice' => $notice->id)),//%3
                    $notice->content,//%4
					$conversationUrl,//%5
                    common_local_url('newnotice',
                                     array('replyto' => $sender->nickname)),//%6
                    common_local_url('replies',
                                     array('nickname' => $user->nickname)),//%7
                    common_local_url('emailsettings'));//%8
	
    common_init_locale();
    mail_to_user($user, $subject, $body);
}

/**
 * Send a mail message to notify a user that her Twitter bridge link
 * has stopped working, and therefore has been removed.  This can
 * happen when the user changes her Twitter password, or otherwise
 * revokes access.
 *
 * @param User $user   user whose Twitter bridge link has been removed
 *
 * @return boolean success flag
 */

function mail_twitter_bridge_removed($user)
{
    common_init_locale($user->language);

    $profile = $user->getProfile();

    $subject = sprintf(_('Your Twitter bridge has been disabled.'));

    $site_name = common_config('site', 'name');

    $body = sprintf(_('Hi, %1$s. We\'re sorry to inform you that your ' .
        'link to Twitter has been disabled. We no longer seem to have ' .
    'permission to update your Twitter status. (Did you revoke ' .
    '%3$s\'s access?)' . "\n\n" .
    'You can re-enable your Twitter bridge by visiting your ' .
    "Twitter settings page:\n\n\t%2\$s\n\n" .
        "Regards,\n%3\$s\n"),
        $profile->getBestName(),
        common_local_url('twittersettings'),
        common_config('site', 'name'));

    common_init_locale();
    return mail_to_user($user, $subject, $body);
}

/**
 * Send a mail message to notify a user that her Facebook Application
 * access has been removed.
 *
 * @param User $user   user whose Facebook app link has been removed
 *
 * @return boolean success flag
 */

function mail_facebook_app_removed($user)
{
    common_init_locale($user->language);

    $profile = $user->getProfile();

    $site_name = common_config('site', 'name');

    $subject = sprintf(
        _('Your %1$s Facebook application access has been disabled.',
            $site_name));

    $body = sprintf(_("Hi, %1\$s. We're sorry to inform you that we are " .
        'unable to update your Facebook status from %2$s, and have disabled ' .
        'the Facebook application for your account. This may be because ' .
        'you have removed the Facebook application\'s authorization, or ' .
        'have deleted your Facebook account.  You can re-enable the ' .
        'Facebook application and automatic status updating by ' .
        "re-installing the %2\$s Facebook application.\n\nRegards,\n\n%2\$s"),
        $user->nickname, $site_name);

    common_init_locale();
    return mail_to_user($user, $subject, $body);

}


