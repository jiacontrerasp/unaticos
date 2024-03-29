<?php
/**
 * StatusNet, the distributed open-source microblogging tool
 *
 * List of a user's subscriptions
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
 * @category  Social
 * @package   StatusNet
 * @author    Evan Prodromou <evan@status.net>
 * @author    Sarven Capadisli <csarven@status.net>
 * @copyright 2008-2009 StatusNet, Inc.
 * @license   http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link      http://status.net/
 */

if (!defined('STATUSNET') && !defined('LACONICA')) {
    exit(1);
}

/**
 * A list of the user's subscriptions
 *
 * @category Social
 * @package  StatusNet
 * @author   Evan Prodromou <evan@status.net>
 * @license  http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link     http://status.net/
 */

if (!defined('STATUSNET') && !defined('LACONICA')) { exit(1); }

class SubscriptionsAction extends GalleryAction
{
    function title()
    {
        if ($this->page == 1) {
            return sprintf(_('%s subscriptions'), $this->user->nickname);
        } else {
            return sprintf(_('%s subscriptions, page %d'),
                           $this->user->nickname,
                           $this->page);
        }
    }

    function showPageNotice()
    {
        $user =& common_current_user();
        if ($user && ($user->id == $this->profile->id)) {
            $this->element('p', null,
                           _('These are the people whose notices '.
                             'you listen to.'));
        } else {
            $this->element('p', null,
                           sprintf(_('These are the people whose '.
                                     'notices %s listens to.'),
                                   $this->profile->nickname));
        }
    }

    function getAllTags()
    {
        return $this->getTags('subscribed', 'subscriber');
    }

    function showContent()
    {
        parent::showContent();

        $offset = ($this->page-1) * PROFILES_PER_PAGE;
        $limit =  PROFILES_PER_PAGE + 1;

        $cnt = 0;

        if ($this->tag) {
            $subscriptions = $this->user->getTaggedSubscriptions($this->tag, $offset, $limit);
        } else {
            $subscriptions = $this->user->getSubscriptions($offset, $limit);
        }

        if ($subscriptions) {
            $subscriptions_list = new SubscriptionsList($subscriptions, $this->user, $this);
            $cnt = $subscriptions_list->show();
            if (0 == $cnt) {
                $this->showEmptyListMessage();
            }
        }

        $subscriptions->free();

        $this->pagination($this->page > 1, $cnt > PROFILES_PER_PAGE,
                          $this->page, 'subscriptions',
                          array('nickname' => $this->user->nickname));
    }

    function showEmptyListMessage()
    {
        if (common_logged_in()) {
            $current_user = common_current_user();
            if ($this->user->id === $current_user->id) {
                $message = _('You\'re not listening to anyone\'s notices right now, try subscribing to people you know. Try [people search](%%action.peoplesearch%%), look for members in groups you\'re interested in and in our [featured users](%%action.featured%%). If you\'re a [Twitter user](%%action.twittersettings%%), you can automatically subscribe to people you already follow there.');
            } else {
                $message = sprintf(_('%s is not listening to anyone.'), $this->user->nickname);
            }
        }
        else {
            $message = sprintf(_('%s is not listening to anyone.'), $this->user->nickname);
        }

        $this->elementStart('div', 'guide');
        $this->raw(common_markup_to_html($message));
        $this->elementEnd('div');
    }

    function showSections()
    {
        parent::showSections();
        $cloud = new SubscriptionsPeopleTagCloudSection($this);
        $cloud->show();

        $cloud2 = new SubscriptionsPeopleSelfTagCloudSection($this);
        $cloud2->show();
    }
}

// XXX SubscriptionsList and SubscriptionList are dangerously close

class SubscriptionsList extends SubscriptionList
{
    function newListItem($profile)
    {
        return new SubscriptionsListItem($profile, $this->owner, $this->action);
    }
}

class SubscriptionsListItem extends SubscriptionListItem
{
    function showProfile()
    {
        $this->startProfile();
        $this->showAvatar();
        $this->showFullName();
        $this->showLocation();
        $this->showHomepage();
        $this->showBio();
        $this->showTags();
        // Relevant portion!
        $cur = common_current_user();
        if (!empty($cur) && $cur->id == $this->owner->id) {
            $this->showOwnerControls();
        }
        $this->endProfile();
    }

    function showOwnerControls()
    {
        $sub = Subscription::pkeyGet(array('subscriber' => $this->owner->id,
                                           'subscribed' => $this->profile->id));
        if (!$sub) {
            return;
        }

        if (!common_config('xmpp', 'enabled') && !common_config('sms', 'enabled')) {
            return;
        }

        $this->out->elementStart('form', array('id' => 'subedit-' . $this->profile->id,
                                          'method' => 'post',
                                          'class' => 'form_subscription_edit',
                                          'action' => common_local_url('subedit')));
        $this->out->hidden('token', common_session_token());
        $this->out->hidden('profile', $this->profile->id);
        if (common_config('xmpp', 'enabled')) {
            $this->out->checkbox('jabber', _('Jabber'), $sub->jabber);
        } else {
            $this->out->hidden('jabber', $sub->jabber);
        }
        if (common_config('sms', 'enabled')) {
            $this->out->checkbox('sms', _('SMS'), $sub->sms);
        } else {
            $this->out->hidden('sms', $sub->sms);
        }
        $this->out->submit('save', _('Save'));
        $this->out->elementEnd('form');
        return;
    }
}
