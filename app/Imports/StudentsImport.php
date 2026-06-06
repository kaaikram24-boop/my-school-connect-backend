<?php

namespace App\Imports;

use App\Models\Student;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class StudentsImport implements ToCollection, WithHeadingRow
{
    protected $classId;
    protected $adminId;
    protected $importedCount = 0;
    protected $errors = [];

    public function __construct($classId = null, $adminId = null)
    {
        $this->classId = $classId;
        $this->adminId = $adminId;
    }

    public function collection(Collection $rows)
    {
        foreach ($rows as $index => $row) {
            try {
                // Vérifier les champs requis
                if (empty($row['first_name']) || empty($row['last_name'])) {
                    $this->errors[] = "Ligne " . ($index + 2) . ": first_name et last_name sont requis";
                    continue;
                }

                // Créer l'étudiant
                Student::create([
                    'first_name' => $row['first_name'],
                    'last_name' => $row['last_name'],
                    'date_of_birth' => $row['date_of_birth'] ?? null,
                    'gender' => $row['gender'] ?? 'male',
                    'student_code' => $row['student_code'] ?? 'STU_' . Str::random(8),
                    'class_id' => $this->classId ?? $row['class_id'] ?? null,
                ]);

                $this->importedCount++;
            } catch (\Exception $e) {
                Log::error('Import error: ' . $e->getMessage());
                $this->errors[] = "Ligne " . ($index + 2) . ": " . $e->getMessage();
            }
        }
    }

    public function getImportedCount()
    {
        return $this->importedCount;
    }

    public function getErrors()
    {
        return $this->errors;
    }
}