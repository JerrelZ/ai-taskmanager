<?php

namespace Database\Seeders;

use App\Enums\ConversationType;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Comment;
use App\Models\Conversation;
use App\Models\Label;
use App\Models\Message;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $demoUser = User::query()->firstWhere('email', 'test@example.com')
            ?? User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        $demoUser->update(['role' => UserRole::Admin]);

        $teammates = collect([
            'Sanne de Vries',
            'Tom Bakker',
            'Lisa Jansen',
            'Daan Visser',
        ])->map(fn (string $name) => User::factory()->create([
            'name' => $name,
            'email' => str($name)->lower()->replace(' ', '.').'@example.com',
        ]));

        $users = $teammates->prepend($demoUser);

        $clients = collect([
            ['name' => 'Acme BV', 'color' => 'blue'],
            ['name' => 'Globex', 'color' => 'green'],
        ])->map(fn (array $data) => Client::create($data));

        // A client contact per client (can log in to their own projects).
        $clients->each(fn (Client $client) => User::factory()->client($client)->create([
            'name' => $client->name.' Contact',
            'email' => str($client->name)->lower()->replace(' ', '').'@client.test',
        ]));

        $labels = collect([
            ['name' => 'Bug', 'color' => 'red'],
            ['name' => 'Feature', 'color' => 'green'],
            ['name' => 'UI', 'color' => 'purple'],
            ['name' => 'Backend', 'color' => 'blue'],
            ['name' => 'Urgent', 'color' => 'orange'],
            ['name' => 'Research', 'color' => 'amber'],
        ])->map(fn (array $label) => Label::create($label));

        $projects = [
            [
                'name' => 'Website Redesign',
                'key' => 'WEB',
                'color' => 'indigo',
                'client_id' => $clients[0]->id,
                'description' => 'Volledige restyling van de marketing website.',
                'repo_path' => '~/Herd/website',
                'stack' => 'Laravel 13, Livewire 4, Flux Pro, Tailwind v4',
                'context' => 'SSR Blade-views, geen SPA. Volg bestaande Flux-conventies en Pest-tests. Dutch UI copy.',
            ],
            [
                'name' => 'Mobiele App',
                'key' => 'APP',
                'color' => 'green',
                'client_id' => $clients[1]->id,
                'description' => 'iOS en Android app voor klanten.',
                'repo_path' => '~/Herd/mobile-app',
                'stack' => 'React Native, Expo, TypeScript',
                'context' => 'Gebruik functionele componenten en hooks. State via Zustand.',
            ],
            [
                'name' => 'Interne Tools',
                'key' => 'INT',
                'color' => 'amber',
                'client_id' => null,
                'description' => 'Verbeteringen aan het admin dashboard.',
                'repo_path' => '~/Herd/admin',
                'stack' => 'Laravel 13, Filament 4',
                'context' => 'Admin panel via Filament resources. Houd policies up-to-date.',
            ],
        ];

        foreach ($projects as $index => $projectData) {
            $project = Project::create([
                ...$projectData,
                'position' => $index,
            ]);

            $this->seedProjectTasks($project, $users, $labels);
            $this->seedProjectMessages($project, $users);
        }

        $this->seedTeamConversations($users, $teammates, $demoUser);

        $this->assignGlobalRanks();
        $this->makeSomeTasksStale();
    }

    /**
     * Seed a team group channel and a direct message.
     *
     * @param  Collection<int, User>  $users
     * @param  Collection<int, User>  $teammates
     */
    private function seedTeamConversations(Collection $users, Collection $teammates, User $demoUser): void
    {
        $general = Conversation::create([
            'type' => ConversationType::Group,
            'name' => 'Algemeen',
            'created_by' => $demoUser->id,
            'last_message_at' => now(),
        ]);
        $general->users()->sync($users->pluck('id')->all());
        Message::factory()->count(6)->for($general)
            ->state(fn () => ['user_id' => $users->random()->id])
            ->create();

        $other = $teammates->first();
        $dm = Conversation::create([
            'type' => ConversationType::Dm,
            'created_by' => $demoUser->id,
            'last_message_at' => now(),
        ]);
        $dm->users()->sync([$demoUser->id, $other->id]);
        Message::factory()->count(4)->for($dm)
            ->state(fn () => ['user_id' => collect([$demoUser->id, $other->id])->random()])
            ->create();
    }

    /**
     * Give every actionable root task an absolute cross-project rank.
     */
    private function assignGlobalRanks(): void
    {
        $rank = 0;

        Task::query()
            ->roots()
            ->actionable()
            ->inRandomOrder()
            ->get()
            ->each(function (Task $task) use (&$rank) {
                $task->update(['rank' => $rank++]);
            });
    }

    /**
     * Push some open tasks' timestamps back so the stale view has content.
     */
    private function makeSomeTasksStale(): void
    {
        Task::query()
            ->roots()
            ->actionable()
            ->inRandomOrder()
            ->limit(8)
            ->get()
            ->each(function (Task $task) {
                $stale = now()->subDays(random_int(20, 60));
                $task->forceFill(['updated_at' => $stale])->saveQuietly();
            });
    }

    /**
     * Seed a handful of chat messages per project.
     *
     * @param  Collection<int, User>  $users
     */
    private function seedProjectMessages(Project $project, Collection $users): void
    {
        $participants = $users;

        if ($project->client_id !== null) {
            $clientUser = User::where('client_id', $project->client_id)->first();
            if ($clientUser !== null) {
                $participants = $users->concat([$clientUser]);
            }
        }

        $conversation = $project->channel();
        $conversation->users()->syncWithoutDetaching($participants->pluck('id')->all());

        Message::factory()
            ->count(random_int(4, 8))
            ->for($conversation)
            ->state(fn () => ['user_id' => $participants->random()->id])
            ->create();

        $conversation->forceFill(['last_message_at' => now()])->save();
    }

    /**
     * @param  Collection<int, User>  $users
     * @param  Collection<int, Label>  $labels
     */
    private function seedProjectTasks(Project $project, Collection $users, Collection $labels): void
    {
        $positions = array_fill_keys(array_column(TaskStatus::cases(), 'value'), 0);

        $taskCount = random_int(16, 24);

        for ($i = 0; $i < $taskCount; $i++) {
            $status = fake()->randomElement(TaskStatus::cases());

            $task = Task::factory()
                ->for($project)
                ->create([
                    'status' => $status,
                    'priority' => fake()->randomElement(TaskPriority::cases()),
                    'assignee_id' => fake()->boolean(75) ? $users->random()->id : null,
                    'created_by' => $users->random()->id,
                    'position' => $positions[$status->value]++,
                ]);

            if (fake()->boolean(70)) {
                $task->labels()->attach(
                    $labels->random(random_int(1, 3))->pluck('id')->all()
                );
            }

            if (fake()->boolean(40)) {
                $subtaskCount = random_int(2, 4);
                for ($s = 0; $s < $subtaskCount; $s++) {
                    Task::factory()->subtaskOf($task)->create([
                        'status' => fake()->boolean(50) ? TaskStatus::Done : TaskStatus::Todo,
                        'priority' => TaskPriority::None,
                        'assignee_id' => fake()->boolean(50) ? $users->random()->id : null,
                        'created_by' => $users->random()->id,
                        'position' => $s,
                    ]);
                }
            }

            if (fake()->boolean(45)) {
                Comment::factory()
                    ->count(random_int(1, 3))
                    ->for($task)
                    ->state(fn () => ['user_id' => $users->random()->id])
                    ->create();
            }
        }
    }
}
