<?php

test('the home page directs guests to staff sign in', function () {
    $this->get('/')->assertRedirectToRoute('login');
});
