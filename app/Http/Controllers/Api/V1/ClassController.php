<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;  // au lieu de App\Models\Classe
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ClassController extends Controller
{
    use ApiResponse;

    /**
     * Display a listing of classes.
     * Admin voit toutes les classes
     * Teacher voit seulement ses classes
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = SchoolClass::with('teacher');
        
        // ✅ Si l'utilisateur est enseignant, filtrer ses classes
        if ($user->isTeacher()) {
            $query->where('teacher_id', $user->id);
        }
        
        // Filtres optionnels
        $classes = $query
            ->when($request->academic_year, fn($q, $y) => $q->where('academic_year', $y))
            ->when($request->boolean('active'), fn($q) => $q->where('is_active', true))
            ->when($request->search, fn($q, $s) => 
                $q->where('name', 'like', "%{$s}%")
                  ->orWhere('grade', 'like', "%{$s}%")
            )
            ->paginate($request->per_page ?? 20);

        return $this->success($classes, 'Classes retrieved successfully.');
    }
    

    /**
     * Store a newly created class.
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:100'],
                'grade' => ['required', 'string', 'max:20'],
                'section' => ['nullable', 'string', 'max:10'],
                'teacher_id' => ['nullable', 'exists:users,id'],
                'academic_year' => ['required', 'string', 'max:10'],
                'is_active' => ['sometimes', 'boolean'],
            ]);

            $class = SchoolClass::create($validated);

            return $this->success($class->load('teacher'), 'Class created successfully.', 201);
            
        } catch (\Exception $e) {
            Log::error('Error creating class: ' . $e->getMessage());
            return $this->error('Error creating class: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Display the specified class.
     */
    public function show(SchoolClass $class)
    {
        $user = request()->user();
        
        // ✅ Vérifier que l'enseignant a accès à cette classe
        if ($user->isTeacher() && $class->teacher_id !== $user->id) {
            return $this->error('Unauthorized to view this class.', 403);
        }
        
        return $this->success($class->load(['teacher', 'students']), 'Class retrieved successfully.');
    }

    /**
     * Update the specified class.
     */
    public function update(Request $request, SchoolClass $class)
    {
        try {
            $user = $request->user();
            
            // ✅ Seul admin peut modifier les classes
            if (!$user->isAdmin()) {
                return $this->error('Only admin can update classes.', 403);
            }
            
            $validated = $request->validate([
                'name' => ['sometimes', 'string', 'max:100'],
                'grade' => ['sometimes', 'string', 'max:20'],
                'section' => ['nullable', 'string', 'max:10'],
                'teacher_id' => ['nullable', 'exists:users,id'],
                'academic_year' => ['sometimes', 'string', 'max:10'],
                'is_active' => ['sometimes', 'boolean'],
            ]);

            $class->update($validated);

            return $this->success($class->fresh('teacher'), 'Class updated successfully.');
            
        } catch (\Exception $e) {
            Log::error('Error updating class: ' . $e->getMessage());
            return $this->error('Error updating class: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Remove the specified class.
     */
    public function destroy(SchoolClass $class)
    {
        try {
            $user = request()->user();
            
            // ✅ Seul admin peut supprimer les classes
            if (!$user->isAdmin()) {
                return $this->error('Only admin can delete classes.', 403);
            }
            
            $class->delete();
            return $this->success(null, 'Class deleted successfully.');
            
        } catch (\Exception $e) {
            Log::error('Error deleting class: ' . $e->getMessage());
            return $this->error('Error deleting class: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get students of a specific class.
     */
    public function students(SchoolClass $class)
    {
        $user = request()->user();
        
        // ✅ Vérifier que l'enseignant a accès à cette classe
        if ($user->isTeacher() && $class->teacher_id !== $user->id) {
            return $this->error('Unauthorized to view students of this class.', 403);
        }
        
        $students = $class->students()
            ->with('parents')
            ->when(request()->search, fn($q, $s) => 
                $q->where('first_name', 'like', "%{$s}%")
                  ->orWhere('last_name', 'like', "%{$s}%")
            )
            ->paginate(request()->per_page ?? 30);
            
        return $this->success($students, 'Students retrieved successfully.');
    }
    
    /**
     * Get classes for teacher (alias pour index avec filtre automatique)
     */
    public function myClasses(Request $request)
    {
        $user = $request->user();
        
        if (!$user->isTeacher()) {
            return $this->error('Only teachers can access this endpoint.', 403);
        }
        
        $classes = SchoolClass::with('teacher')
            ->where('teacher_id', $user->id)
            ->where('is_active', true)
            ->get();
            
        return $this->success($classes, 'My classes retrieved successfully.');
    }
}