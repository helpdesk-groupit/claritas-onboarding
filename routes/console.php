<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('employees:activate')->everyMinute();
Schedule::command('offboarding:notify')->everyMinute();