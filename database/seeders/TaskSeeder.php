<?php

namespace Database\Seeders;

use App\Models\Task;
use Illuminate\Database\Seeder;

class TaskSeeder extends Seeder
{
    public function run()
    {
        $tasks = [
            ['name' => 'Devoirs', 'default_points' => 5, 'icon' => '📚', 'color' => 'emerald'],
            ['name' => 'Lecture', 'default_points' => 3, 'icon' => '📖', 'color' => 'blue'],
            ['name' => 'Écoute', 'default_points' => 2, 'icon' => '👂', 'color' => 'cyan'],
            ['name' => 'Comportement', 'default_points' => 1, 'icon' => '🙋', 'color' => 'yellow'],
        ];
        
        foreach ($tasks as $task) {
            Task::create($task);
        }
    }
}