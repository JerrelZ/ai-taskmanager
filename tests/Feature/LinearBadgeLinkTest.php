<?php

use App\Models\Task;

it('builds a Linear deep link from the imported identifier', function () {
    config(['services.linear.workspace' => 'blogmatcher']);

    $task = Task::factory()->make(['linear_id' => 'REVBOOS-10']);

    expect($task->linearUrl())->toBe('https://linear.app/blogmatcher/issue/REVBOOS-10');
});

it('has no Linear link when the ticket was not imported', function () {
    $task = Task::factory()->make(['linear_id' => null]);

    expect($task->linearUrl())->toBeNull();
});
