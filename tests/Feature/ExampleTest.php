<?php

test('the home route redirects to the tickets overview', function () {
    $response = $this->get(route('home'));

    $response->assertRedirect(route('tickets.index'));
});
