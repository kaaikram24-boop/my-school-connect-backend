<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\User;
use App\Models\StudentParent;
use App\Models\Trip;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StudentController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $user = $request->user();
        $query = Student::with(['class']);
        
        if ($user->isParent()) {
            $query->whereHas('parents', function($q) use ($user) {
                $q->where('parent_id', $user->id);
            });
        }
        
        if ($user->isAdmin() && $request->has('class_id')) {
            $query->where('class_id', $request->class_id);
        }
        
        $students = $query->paginate(30);
        
        $students->getCollection()->transform(function($student) {
            $student->total_points = $student->points()->sum('points');
            return $student;
        });
        
        return response()->json([
            'success' => true,
            'data' => $students
        ]);
    }

    public function store(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user->isAdmin()) {
                return $this->error('Unauthorized: Only admin can create students.', 403);
            }

            $validated = $request->validate([
                'first_name' => 'required|string|max:80',
                'last_name' => 'required|string|max:80',
                'date_of_birth' => 'nullable|date',
                'gender' => 'nullable|in:male,female,other',
                'class_id' => 'required|exists:classes,id',
                'parent_ids' => 'nullable|array',
                'parent_ids.*' => 'exists:users,id',
            ]);

            $student = Student::create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'date_of_birth' => $validated['date_of_birth'] ?? null,
                'gender' => $validated['gender'] ?? null,
                'class_id' => $validated['class_id'],
                'student_code' => 'STU_' . strtoupper(Str::random(8)) . '_' . time(),
            ]);

            if (!empty($validated['parent_ids'])) {
                $pivotData = [];
                foreach ($validated['parent_ids'] as $index => $parentId) {
                    $pivotData[$parentId] = [
                        'relation' => 'parent',
                        'is_primary' => $index === 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                $student->parents()->sync($pivotData);
            }

            $student->total_points = 0;

            return $this->success(
                $student->load(['class', 'parents']), 
                'Student created successfully.', 
                201
            );
            
        } catch (\Exception $e) {
            return $this->error('Error creating student: ' . $e->getMessage(), 500);
        }
    }

    public function show(Request $request, Student $student)
    {
        $user = $request->user();
        
        if (!$user->isAdmin() && 
            !($user->isTeacher() && $student->class->teacher_id === $user->id) &&
            !($user->isParent() && $student->parents->contains($user->id))) {
            return $this->error('Unauthorized to view this student.', 403);
        }

        $student->total_points = $student->points()->sum('points');

        return $this->success(
            $student->load(['class', 'parents', 'points']),
            'Student retrieved successfully.'
        );
    }

    public function update(Request $request, Student $student)
    {
        try {
            $user = $request->user();
            
            if (!$user->isAdmin()) {
                return $this->error('Unauthorized: Only admin can update students.', 403);
            }

            $validated = $request->validate([
                'first_name' => 'sometimes|string|max:80',
                'last_name' => 'sometimes|string|max:80',
                'date_of_birth' => 'nullable|date',
                'gender' => 'nullable|in:male,female,other',
                'class_id' => 'sometimes|exists:classes,id',
                'is_active' => 'sometimes|boolean',
            ]);

            $student->update($validated);
            $student->total_points = $student->points()->sum('points');

            return $this->success(
                $student->fresh(['class', 'parents']), 
                'Student updated successfully.'
            );
            
        } catch (\Exception $e) {
            return $this->error('Error updating student: ' . $e->getMessage(), 500);
        }
    }

    /**
     * ✅ Assigner un parent à un étudiant (Admin seulement)
     * POST /api/v1/admin/students/{student}/assign-parent
     */
    public function assignParent(Request $request, Student $student)
    {
        try {
            $user = $request->user();
            
            if (!$user->isAdmin()) {
                return $this->error('Unauthorized: Only admin can assign parents.', 403);
            }

            $validated = $request->validate([
                'parent_id' => 'required|exists:users,id',
                'relation' => 'nullable|string|max:30|default:parent',
                'is_primary' => 'nullable|boolean|default:true'
            ]);

            $parent = User::find($validated['parent_id']);
            
            // Vérifier que l'utilisateur a le rôle parent
            if (!$parent->isParent()) {
                return $this->error('Cet utilisateur n\'est pas un parent.', 422);
            }

            // Vérifier si la relation existe déjà
            $existingRelation = StudentParent::where('student_id', $student->id)
                ->where('parent_id', $validated['parent_id'])
                ->first();

            if ($existingRelation) {
                return $this->error('Ce parent est déjà lié à cet étudiant.', 422);
            }

            // Créer la relation
            StudentParent::create([
                'student_id' => $student->id,
                'parent_id' => $validated['parent_id'],
                'relation' => $validated['relation'] ?? 'parent',
                'is_primary' => $validated['is_primary'] ?? true,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // Si c'est le parent principal, on peut aussi le définir comme is_primary = true
            if ($validated['is_primary'] ?? true) {
                StudentParent::where('student_id', $student->id)
                    ->where('parent_id', '!=', $validated['parent_id'])
                    ->update(['is_primary' => false]);
            }

            return $this->success(
                $student->load(['class', 'parents']),
                'Parent assigné avec succès.'
            );

        } catch (\Exception $e) {
            return $this->error('Erreur lors de l\'assignation: ' . $e->getMessage(), 500);
        }
    }

    /**
     * ✅ Supprimer la liaison parent-étudiant (Admin seulement)
     * DELETE /api/v1/admin/students/{student}/remove-parent/{parentId}
     */
    public function removeParent(Student $student, $parentId)
    {
        try {
            $user = request()->user();
            
            if (!$user->isAdmin()) {
                return $this->error('Unauthorized: Only admin can remove parents.', 403);
            }

            $deleted = StudentParent::where('student_id', $student->id)
                ->where('parent_id', $parentId)
                ->delete();

            if (!$deleted) {
                return $this->error('Relation non trouvée.', 404);
            }

            return $this->success(null, 'Parent retiré avec succès.');

        } catch (\Exception $e) {
            return $this->error('Erreur lors de la suppression: ' . $e->getMessage(), 500);
        }
    }

    public function destroy(Student $student)
    {
        try {
            $user = request()->user();
            
            if (!$user->isAdmin()) {
                return $this->error('Unauthorized: Only admin can delete students.', 403);
            }
            
            DB::beginTransaction();
            
            $student->points()->delete();
            \App\Models\StudentTaskPoint::where('student_id', $student->id)->delete();
            $student->parents()->detach();
            $student->delete();
            
            DB::commit();
            
            return $this->success(null, 'Student deleted successfully.');
            
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Error deleting student: ' . $e->getMessage(), 500);
        }
    }

    public function points(Student $student)
    {
        $classicPoints = $student->points()->with('awardedBy')->latest()->paginate(20);
        
        $taskPoints = \App\Models\StudentTaskPoint::where('student_id', $student->id)
            ->with('task')
            ->get();
        
        $totalTaskPoints = $taskPoints->sum('points');
        $tasks = \App\Models\Task::all();
        $taskPointsArray = [];
        
        foreach ($tasks as $task) {
            $pointsObtained = $taskPoints->where('task_id', $task->id)->sum('points');
            $taskPointsArray[] = [
                'task_id' => $task->id,
                'task_name' => $task->name,
                'points_obtained' => min($pointsObtained, $task->max_points),
                'max_points' => $task->max_points,
                'icon' => $task->icon,
                'color' => $task->color,
            ];
        }
        
        return response()->json([
            'success' => true,
            'data' => [
                'student' => $student,
                'classic_points' => $classicPoints,
                'task_points' => $taskPointsArray,
                'total_points' => min($totalTaskPoints, 50)
            ]
        ]);
    }

    public function getStudentTaskPoints($studentId)
    {
        try {
            $student = Student::findOrFail($studentId);
            
            $user = request()->user();
            if (!$user->isAdmin() && 
                !($user->isTeacher() && $student->class->teacher_id === $user->id) &&
                !($user->isParent() && $student->parents->contains($user->id))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }
            
            $taskPoints = \App\Models\StudentTaskPoint::where('student_id', $studentId)
                ->with(['task', 'teacher'])
                ->get();
            
            $total = $taskPoints->sum('points');
            $total = min($total, 50);
            
            $tasks = \App\Models\Task::all();
            $tasksWithPoints = [];
            
            foreach ($tasks as $task) {
                $taskPoint = $taskPoints->where('task_id', $task->id)->first();
                $points = $taskPoint ? $taskPoint->points : 0;
                
                $tasksWithPoints[] = [
                    'id' => $taskPoint ? $taskPoint->id : null,
                    'task_id' => $task->id,
                    'task_name' => $task->name,
                    'task_icon' => $task->icon,
                    'task_color' => $task->color,
                    'default_points' => $task->default_points,
                    'max_points' => $task->max_points,
                    'points_obtained' => min($points, $task->max_points),
                    'comment' => $taskPoint ? $taskPoint->comment : null,
                    'date' => $taskPoint ? $taskPoint->date : null,
                    'teacher' => $taskPoint && $taskPoint->teacher ? [
                        'id' => $taskPoint->teacher->id,
                        'name' => $taskPoint->teacher->name,
                    ] : null,
                    'history' => $taskPoints->where('task_id', $task->id)->values()->map(function($p) {
                        return [
                            'id' => $p->id,
                            'points' => $p->points,
                            'comment' => $p->comment,
                            'date' => $p->date,
                            'teacher_name' => $p->teacher ? $p->teacher->name : null,
                        ];
                    })
                ];
            }
            
            return response()->json([
                'success' => true,
                'data' => [
                    'student' => $student,
                    'grand_total' => $total,
                    'max_total' => 50,
                    'tasks' => $tasksWithPoints
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ Get the current trip for a student (for parents)
     * GET /api/v1/parent/students/{student}/trip
     */
    public function getStudentTrip($id)
    {
        try {
            $student = Student::find($id);
            
            if (!$student) {
                return response()->json([
                    'success' => false,
                    'message' => 'Student not found'
                ], 404);
            }
            
            // Vérifier que le parent a accès à cet étudiant
            $user = request()->user();
            if ($user && $user->isParent()) {
                $hasAccess = \DB::table('student_parents')
                    ->where('student_id', $id)
                    ->where('parent_id', $user->id)
                    ->exists();
                
                if (!$hasAccess) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unauthorized'
                    ], 403);
                }
            }
            
            // Récupérer le trajet du jour
            $trip = Trip::where('student_id', $id)
                ->whereDate('date', today())
                ->first();
            
            return response()->json([
                'success' => true,
                'data' => $trip
            ]);
            
        } catch (\Exception $e) {
            Log::error('Get Student Trip Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching trip: ' . $e->getMessage()
            ], 500);
        }
    }
}