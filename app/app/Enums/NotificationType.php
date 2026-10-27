<?php

namespace App\Enums;

/**
 * Dashboard notification types for IT administrators (PRD FR-05).
 */
enum NotificationType: string
{
    case NewTicket = 'new_ticket';
    case TicketAssigned = 'ticket_assigned';
    case StatusChanged = 'status_changed';
    case RelinkRequest = 'relink_request';
    case PendingTicketsAlert = 'pending_tickets_alert';
    case WeeklySummary = 'weekly_summary';
    case IncidentBroadcast = 'incident_broadcast';
}
