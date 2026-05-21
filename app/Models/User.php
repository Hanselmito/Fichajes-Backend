<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $guarded = [];

    protected $hidden = [
        'password_hash',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'can_view_reports' => 'boolean',
            'can_view_all_records' => 'boolean',
            'can_view_all_bolsa' => 'boolean',
            'can_view_all_dashboard' => 'boolean',
            'can_view_user_overview' => 'boolean',
            'can_view_coordinators_in_employee_view' => 'boolean',
            'can_view_all_vacations' => 'boolean',
            'can_promote_to_coordinator' => 'boolean',
            'work_hours' => 'decimal:2',
            'weekly_hours' => 'decimal:2',
            'contract_start' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'force_logout_after' => 'datetime',
            'can_view_reports_zone_ids' => 'array',
            'can_view_all_records_zone_ids' => 'array',
            'can_view_all_bolsa_zone_ids' => 'array',
            'can_view_all_dashboard_zone_ids' => 'array',
            'can_view_user_overview_zone_ids' => 'array',
            'can_view_coordinators_in_employee_view_zone_ids' => 'array',
            'can_view_all_vacations_zone_ids' => 'array',
        ];
    }

    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(Record::class, 'employee_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function notificationSetting(): HasOne
    {
        return $this->hasOne(NotificationSetting::class);
    }

    public function vacations(): HasMany
    {
        return $this->hasMany(Vacation::class, 'employee_id');
    }

    public function approvedVacations(): HasMany
    {
        return $this->hasMany(Vacation::class, 'approved_by');
    }

    public function vacationRequests(): HasMany
    {
        return $this->hasMany(VacationRequest::class, 'employee_id');
    }

    public function approvedVacationRequests(): HasMany
    {
        return $this->hasMany(VacationRequest::class, 'approved_by');
    }

    public function employeeSchedules(): HasMany
    {
        return $this->hasMany(EmployeeSchedule::class, 'employee_id');
    }

    public function breaks(): HasMany
    {
        return $this->hasMany(BreakModel::class, 'employee_id');
    }

    public function incidencias(): HasMany
    {
        return $this->hasMany(Incidencia::class, 'employee_id');
    }

    public function coordinatedIncidencias(): HasMany
    {
        return $this->hasMany(Incidencia::class, 'coordinador_id');
    }

    public function modificationRequests(): HasMany
    {
        return $this->hasMany(ModificationRequest::class, 'employee_id');
    }

    public function approvedModificationRequests(): HasMany
    {
        return $this->hasMany(ModificationRequest::class, 'approved_by');
    }

    public function modificationConfirmations(): HasMany
    {
        return $this->hasMany(ModificationConfirmation::class, 'employee_id');
    }

    public function appliedModificationConfirmations(): HasMany
    {
        return $this->hasMany(ModificationConfirmation::class, 'modified_by');
    }

    public function createdSchedules(): HasMany
    {
        return $this->hasMany(Schedule::class, 'created_by');
    }

    public function scheduleAssignments(): HasMany
    {
        return $this->hasMany(ScheduleAssignment::class, 'employee_id');
    }

    public function createdScheduleExceptions(): HasMany
    {
        return $this->hasMany(ScheduleException::class, 'created_by');
    }

    public function scheduleSubstitutions(): HasMany
    {
        return $this->hasMany(ScheduleException::class, 'substitute_employee_id');
    }

    public function createdCalendars(): HasMany
    {
        return $this->hasMany(Calendar::class, 'created_by');
    }

    public function createdCalendarHolidays(): HasMany
    {
        return $this->hasMany(CalendarHoliday::class, 'created_by');
    }

    public function createdZoneHolidays(): HasMany
    {
        return $this->hasMany(ZoneHoliday::class, 'created_by');
    }
}
