<?php

namespace App\Enums;

/**
 * The role a client-side person plays on a specific project. The label is
 * rendered bilingually on the client via `projects.contact_role_{value}`.
 */
enum ProjectContactRole: string
{
    case Supervisor = 'supervisor';
    case Engineer = 'engineer';
    case ProjectManager = 'project_manager';
    case Other = 'other';
}
