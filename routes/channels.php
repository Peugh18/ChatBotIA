<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('client.{clientId}', function ($user, $clientId) {
    return true; // All authenticated staff can listen to any client channel
});

Broadcast::channel('crm-dashboard', function ($user) {
    return true;
});
