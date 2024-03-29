#!/usr/bin/env php
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

define('INSTALLDIR', realpath(dirname(__FILE__) . '/..'));

$shortoptions = 'i::';
$longoptions = array('id::');

$helptext = <<<END_OF_PUBLIC_HELP
Daemon script for pushing new notices to public XMPP subscribers.

    -i --id           Identity (default none)

END_OF_PUBLIC_HELP;

require_once INSTALLDIR.'/scripts/commandline.inc';

require_once INSTALLDIR . '/lib/jabber.php';
require_once INSTALLDIR . '/lib/xmppqueuehandler.php';

class PublicQueueHandler extends XmppQueueHandler
{

    function transport()
    {
        return 'public';
    }

    function handle_notice($notice)
    {
        try {
            return jabber_public_notice($notice);
        } catch (XMPPHP_Exception $e) {
            $this->log(LOG_ERR, "Got an XMPPHP_Exception: " . $e->getMessage());
            die($e->getMessage());
        }
    }
}

// Abort immediately if xmpp is not enabled, otherwise the daemon chews up
// lots of CPU trying to connect to unconfigured servers
if (common_config('xmpp','enabled')==false) {
    print "Aborting daemon - xmpp is disabled\n";
    exit();
}

if (have_option('i')) {
    $id = get_option_value('i');
} else if (have_option('--id')) {
    $id = get_option_value('--id');
} else if (count($args) > 0) {
    $id = $args[0];
} else {
    $id = null;
}

$handler = new PublicQueueHandler($id);

$handler->runOnce();
