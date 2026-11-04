<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The 12 approved job titles and their 36 reporting templates (Daily, Weekly, Monthly).
 *
 * This file is the source the template SQL is generated from (bin/make-report-templates-sql.php);
 * the app itself reads templates from report_templates / report_template_fields.
 *
 * A field is [key, label, type, flags] where type is int|dec|money|pct|text and flags has
 * "k" (key performance indicator, shown on dashboards) and/or "r" (required on submit).
 * A calculated field is [key, label, "=formula", display type, flags]: it is filled from data
 * already recorded (approved daily reports, and approved weekly reports for monthly), so nobody
 * types the same figure twice. Formula functions: sum avg last (daily data), wsum wavg wlast
 * (weekly reports), pct(a,b) ratio(a,b) success(ok,failed) accuracy(total,errors), with + and -.
 */
final class ReportCatalog
{
    public const PERIODS = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'];

    /** role code => [approved job title, performance area shown on the Directors' dashboard] */
    public const ROLES = [
        'director'                     => ['Director', 'Company leadership'],
        'transport_manager'            => ['Transport Manager', 'Transport'],
        'logistics_coordinator'        => ['Logistics Co-ordinator', 'Logistics'],
        'delivery_service_manager'     => ['Delivery Service Manager', 'Delivery service'],
        'fleet_manager'                => ['Fleet Manager', 'Fleet'],
        'business_support_manager'     => ['Business Support Manager', 'Business support'],
        'freight_administrator'        => ['Freight Administrator', 'Freight administration'],
        'distribution_service_manager' => ['Distribution Service Manager', 'Distribution'],
        'route_planning_manager'       => ['Route Planning Manager', 'Route planning'],
        'business_development_manager' => ['Business Development Manager', 'Business development'],
        'operations_supervisor'        => ['Operations Supervisor', 'Operations'],
        'financial_accounts_manager'   => ['Financial Accounts Manager', 'Financial accounts'],
    ];

    /** Fields every report has, after the role-specific questions. */
    public const STANDARD = [
        ['tasks_completed', 'Tasks completed', 'text', 'r'],
        ['tasks_outstanding', 'Tasks outstanding', 'text', ''],
        ['problems', 'Problems encountered', 'text', ''],
        ['actions_taken', 'Actions taken', 'text', ''],
        ['support_required', 'Support required', 'text', ''],
        ['comments', 'Additional comments', 'text', ''],
    ];

    public static function templates(): array
    {
        return [
            'director' => [
                'daily' => [
                    ['company_position', 'Overall company position', 'text', 'r'],
                    ['operational_developments', 'Important operational developments', 'text', ''],
                    ['critical_issues', 'Critical business issues', 'text', ''],
                    ['decisions_made', 'Important decisions made', 'text', ''],
                    ['financial_concerns', 'Financial or commercial concerns', 'text', ''],
                    ['immediate_attention', 'Issues requiring immediate attention', 'text', ''],
                ],
                'weekly' => [
                    ['business_performance', 'Overall business performance', 'text', 'r'],
                    ['department_updates', 'Department management updates', 'text', ''],
                    ['revenue_profitability', 'Revenue and profitability overview', 'text', ''],
                    ['operational_performance', 'Operational performance', 'text', ''],
                    ['bd_opportunities', 'Business development opportunities', 'text', ''],
                    ['risks_decisions', 'Major risks and decisions', 'text', ''],
                ],
                'monthly' => [
                    ['company_performance', 'Company performance', 'text', 'r'],
                    ['objectives_achieved', 'Strategic objectives achieved', 'text', ''],
                    ['department_comparison', 'Department performance comparisons', 'text', ''],
                    ['business_growth', 'Business growth', 'text', ''],
                    ['financial_performance', 'Financial performance', 'text', ''],
                    ['strategic_decisions', 'Strategic decisions', 'text', ''],
                    ['next_month_priorities', 'Priorities for the next month', 'text', 'r'],
                ],
            ],
            'transport_manager' => [
                'daily' => [
                    ['drivers_available', 'Drivers available', 'int', 'kr'],
                    ['drivers_allocated', 'Drivers allocated', 'int', 'r'],
                    ['vehicles_available', 'Vehicles available', 'int', 'r'],
                    ['vehicles_allocated', 'Vehicles allocated', 'int', 'kr'],
                    ['jobs_received', 'Jobs received', 'int', 'kr'],
                    ['jobs_completed', 'Jobs completed', 'int', 'kr'],
                    ['outstanding_deliveries', 'Outstanding deliveries', 'int', 'r'],
                    ['delayed_deliveries', 'Delayed deliveries', 'int', 'kr'],
                    ['failed_deliveries', 'Failed deliveries', 'int', 'kr'],
                    ['drivers_present', 'Driver attendance: drivers present', 'int', 'r'],
                    ['drivers_absent', 'Driver attendance: drivers absent', 'int', 'r'],
                    ['accidents_incidents', 'Accidents and incidents (number)', 'int', 'kr'],
                    ['incident_details', 'Accidents and incidents: details', 'text', ''],
                    ['transport_issues', 'Transport issues', 'text', ''],
                    ['corrective_actions', 'Corrective actions', 'text', ''],
                ],
                'weekly' => [
                    ['total_jobs_completed', 'Total jobs completed', '=sum(jobs_completed)', 'int', 'k'],
                    ['driver_productivity', 'Driver productivity (jobs per allocated driver-day)', '=ratio(sum(jobs_completed),sum(drivers_allocated))', 'dec', 'k'],
                    ['vehicle_utilisation', 'Vehicle utilisation (%)', '=pct(sum(vehicles_allocated),sum(vehicles_available))', 'pct', 'k'],
                    ['delivery_success_rate', 'Delivery success rate (%)', '=success(sum(jobs_completed),sum(failed_deliveries))', 'pct', 'k'],
                    ['fuel_costs', 'Fuel costs (£)', 'money', 'kr'],
                    ['transport_costs', 'Other transport costs (£)', 'money', 'r'],
                    ['driver_performance', 'Driver performance', 'text', 'r'],
                    ['accidents_total', 'Accidents and incidents', '=sum(accidents_incidents)', 'int', ''],
                    ['breakdowns', 'Vehicle breakdowns', 'int', 'r'],
                    ['outstanding_issues', 'Outstanding operational issues', 'text', ''],
                ],
                'monthly' => [
                    ['transport_performance', 'Overall transport performance', 'text', 'r'],
                    ['jobs_completed_month', 'Jobs completed', '=sum(jobs_completed)', 'int', 'k'],
                    ['transport_costs_month', 'Transport costs (£, fuel and other)', '=wsum(fuel_costs)+wsum(transport_costs)', 'money', 'k'],
                    ['cost_efficiency', 'Transport costs and efficiency', 'text', ''],
                    ['driver_trends', 'Driver performance trends', 'text', ''],
                    ['vehicle_utilisation_month', 'Vehicle utilisation (%)', '=pct(sum(vehicles_allocated),sum(vehicles_available))', 'pct', 'k'],
                    ['delivery_success_month', 'Delivery success rate (%)', '=success(sum(jobs_completed),sum(failed_deliveries))', 'pct', 'k'],
                    ['delivery_performance', 'Delivery performance', 'text', ''],
                    ['operational_improvements', 'Operational improvements', 'text', ''],
                    ['transport_budget', 'Transport budget for the month (£)', 'money', 'r'],
                    ['recommendations', 'Recommendations', 'text', 'r'],
                ],
            ],
            'logistics_coordinator' => [
                'daily' => [
                    ['jobs_received', 'Jobs received', 'int', 'kr'],
                    ['jobs_processed', 'Jobs processed', 'int', 'kr'],
                    ['collections_coordinated', 'Collections coordinated', 'int', 'kr'],
                    ['deliveries_coordinated', 'Deliveries coordinated', 'int', 'kr'],
                    ['communication', 'Customer and driver communication', 'text', ''],
                    ['outstanding_jobs', 'Outstanding jobs', 'int', 'r'],
                    ['delayed_consignments', 'Delayed consignments', 'int', 'kr'],
                    ['documents_completed', 'Documentation completed (number of documents)', 'int', 'r'],
                    ['logistics_problems', 'Logistics problems', 'text', ''],
                ],
                'weekly' => [
                    ['total_coordinated', 'Total jobs coordinated', '=sum(collections_coordinated)+sum(deliveries_coordinated)', 'int', 'k'],
                    ['jobs_completed_week', 'Jobs completed', '=sum(jobs_processed)', 'int', ''],
                    ['jobs_outstanding_week', 'Jobs outstanding at the end of the week', '=last(outstanding_jobs)', 'int', ''],
                    ['coordination_efficiency', 'Coordination efficiency (% of jobs received that were processed)', '=pct(sum(jobs_processed),sum(jobs_received))', 'pct', 'k'],
                    ['delayed_week', 'Delayed consignments', '=sum(delayed_consignments)', 'int', 'k'],
                    ['stakeholder_communication', 'Customer and supplier communication', 'text', ''],
                    ['documentation_errors', 'Documentation errors', 'int', 'kr'],
                    ['operational_improvements', 'Operational improvements', 'text', ''],
                ],
                'monthly' => [
                    ['jobs_received_month', 'Monthly logistics activity: jobs received', '=sum(jobs_received)', 'int', 'k'],
                    ['jobs_processed_month', 'Monthly logistics activity: jobs processed', '=sum(jobs_processed)', 'int', 'k'],
                    ['coordination_performance', 'Coordination performance', 'text', 'r'],
                    ['service_efficiency', 'Service efficiency (% processed)', '=pct(sum(jobs_processed),sum(jobs_received))', 'pct', 'k'],
                    ['recurring_problems', 'Recurring operational problems', 'text', ''],
                    ['documentation_accuracy', 'Documentation accuracy (%)', '=accuracy(sum(documents_completed),wsum(documentation_errors))', 'pct', 'k'],
                    ['process_improvements', 'Process improvements', 'text', ''],
                    ['recommendations', 'Recommendations', 'text', 'r'],
                ],
            ],
            'delivery_service_manager' => [
                'daily' => [
                    ['deliveries_scheduled', 'Deliveries scheduled', 'int', 'kr'],
                    ['deliveries_completed', 'Deliveries completed', 'int', 'kr'],
                    ['failed_deliveries', 'Failed deliveries', 'int', 'kr'],
                    ['delivery_delays', 'Delivery delays', 'int', 'kr'],
                    ['customer_complaints', 'Customer complaints', 'int', 'kr'],
                    ['driver_performance', 'Driver performance', 'text', ''],
                    ['service_issues', 'Delivery service issues', 'text', ''],
                    ['corrective_actions', 'Corrective actions', 'text', ''],
                ],
                'weekly' => [
                    ['total_deliveries', 'Total deliveries completed', '=sum(deliveries_completed)', 'int', 'k'],
                    ['success_rate', 'Delivery success rate (%)', '=success(sum(deliveries_completed),sum(failed_deliveries))', 'pct', 'k'],
                    ['failed_week', 'Failed deliveries', '=sum(failed_deliveries)', 'int', 'k'],
                    ['delayed_week', 'Delayed deliveries', '=sum(delivery_delays)', 'int', ''],
                    ['customer_satisfaction', 'Customer satisfaction score (%)', 'pct', 'kr'],
                    ['complaints_received', 'Complaints received', '=sum(customer_complaints)', 'int', 'k'],
                    ['complaints_resolved', 'Complaints resolved', 'int', 'r'],
                    ['driver_productivity', 'Driver productivity', 'text', ''],
                    ['service_performance_issues', 'Service performance issues', 'text', ''],
                ],
                'monthly' => [
                    ['service_performance', 'Overall delivery service performance', 'text', 'r'],
                    ['success_rate_month', 'Delivery success rate (%)', '=success(sum(deliveries_completed),sum(failed_deliveries))', 'pct', 'k'],
                    ['success_trends', 'Delivery success trends', 'text', ''],
                    ['satisfaction_month', 'Customer satisfaction (average %, from weekly reports)', '=wavg(customer_satisfaction)', 'pct', 'k'],
                    ['failed_month', 'Failed deliveries', '=sum(failed_deliveries)', 'int', 'k'],
                    ['failed_analysis', 'Failed delivery analysis', 'text', ''],
                    ['service_quality', 'Service quality', 'text', ''],
                    ['driver_trends', 'Driver performance trends', 'text', ''],
                    ['recommendations', 'Service improvement recommendations', 'text', 'r'],
                ],
            ],
            'fleet_manager' => [
                'daily' => [
                    ['vehicles_available', 'Vehicles available', 'int', 'kr'],
                    ['inspections', 'Vehicle inspections completed', 'int', 'r'],
                    ['defects_reported', 'Defects reported', 'int', 'kr'],
                    ['vehicles_off_road', 'Vehicles off the road (VOR)', 'int', 'kr'],
                    ['repairs_completed', 'Repairs completed', 'int', 'r'],
                    ['maintenance_outstanding', 'Maintenance jobs outstanding', 'int', 'r'],
                    ['mot_insurance', 'MOT and insurance concerns', 'text', ''],
                    ['vehicle_incidents', 'Vehicle incidents (number)', 'int', 'kr'],
                    ['incident_details', 'Vehicle incidents: details', 'text', ''],
                ],
                'weekly' => [
                    ['fleet_availability', 'Fleet availability (average vehicles available per day)', '=avg(vehicles_available)', 'dec', 'k'],
                    ['vor_average', 'Vehicles off the road (average per day)', '=avg(vehicles_off_road)', 'dec', ''],
                    ['vehicle_utilisation', 'Vehicle utilisation (%)', 'pct', 'kr'],
                    ['maintenance_completed', 'Maintenance and repairs completed', '=sum(repairs_completed)', 'int', 'k'],
                    ['breakdowns', 'Breakdowns', 'int', 'kr'],
                    ['repair_costs', 'Repair costs (£)', 'money', 'kr'],
                    ['vehicle_compliance', 'Vehicle compliance', 'text', ''],
                    ['outstanding_repairs', 'Outstanding repairs at the end of the week', '=last(maintenance_outstanding)', 'int', ''],
                ],
                'monthly' => [
                    ['fleet_performance', 'Fleet performance', 'text', 'r'],
                    ['maintenance_costs', 'Total maintenance costs (£)', '=wsum(repair_costs)', 'money', 'k'],
                    ['downtime', 'Vehicle downtime (vehicle-days off the road)', '=sum(vehicles_off_road)', 'int', 'k'],
                    ['breakdowns_month', 'Breakdowns', '=wsum(breakdowns)', 'int', 'k'],
                    ['breakdown_trends', 'Breakdown trends', 'text', ''],
                    ['replacement_requirements', 'Vehicle replacement requirements', 'text', ''],
                    ['mot_insurance_compliance', 'Insurance and MOT compliance', 'text', 'r'],
                    ['recommendations', 'Fleet efficiency recommendations', 'text', 'r'],
                ],
            ],
            'business_support_manager' => [
                'daily' => [
                    ['administrative_activities', 'Administrative activities', 'text', 'r'],
                    ['staff_support', 'Internal staff support requests handled', 'int', 'kr'],
                    ['documents_processed', 'Documents processed', 'int', 'kr'],
                    ['support_requests', 'Business support requests received', 'int', 'kr'],
                    ['outstanding_tasks', 'Outstanding tasks', 'int', 'kr'],
                    ['management_attention', 'Issues requiring management attention', 'text', ''],
                ],
                'weekly' => [
                    ['workload', 'Business support workload (requests received)', '=sum(support_requests)', 'int', 'k'],
                    ['administrative_performance', 'Administrative performance', 'text', 'r'],
                    ['tasks_completed_week', 'Tasks completed (support handled and documents processed)', '=sum(staff_support)+sum(documents_processed)', 'int', 'k'],
                    ['outstanding_requests', 'Outstanding requests at the end of the week', '=last(outstanding_tasks)', 'int', 'k'],
                    ['department_support', 'Department support', 'text', ''],
                    ['process_issues', 'Process issues', 'text', ''],
                    ['improvement_actions', 'Improvement actions', 'text', ''],
                ],
                'monthly' => [
                    ['support_performance', 'Business support performance', 'text', 'r'],
                    ['administrative_efficiency', 'Administrative efficiency', 'text', ''],
                    ['requests_month', 'Department support statistics: requests received', '=sum(support_requests)', 'int', 'k'],
                    ['handled_month', 'Department support statistics: requests handled', '=sum(staff_support)', 'int', 'k'],
                    ['documents_month', 'Documents processed', '=sum(documents_processed)', 'int', ''],
                    ['process_improvements', 'Process improvements', 'text', ''],
                    ['resource_requirements', 'Resource requirements', 'text', ''],
                    ['outstanding_issues', 'Outstanding issues', 'text', ''],
                    ['recommendations', 'Recommendations', 'text', 'r'],
                ],
            ],
            'freight_administrator' => [
                'daily' => [
                    ['bookings_processed', 'Freight bookings processed', 'int', 'kr'],
                    ['consignment_documents', 'Consignment documentation completed', 'int', 'r'],
                    ['delivery_paperwork', 'Delivery paperwork completed', 'int', 'r'],
                    ['stakeholder_updates', 'Customer and supplier updates', 'text', ''],
                    ['invoices_processed', 'Invoices and supporting documents processed', 'int', 'kr'],
                    ['missing_documentation', 'Missing documentation', 'int', 'kr'],
                    ['booking_errors', 'Booking errors', 'int', 'kr'],
                    ['outstanding_admin', 'Outstanding administrative tasks', 'int', 'r'],
                ],
                'weekly' => [
                    ['total_bookings', 'Total bookings processed', '=sum(bookings_processed)', 'int', 'k'],
                    ['documentation_accuracy', 'Documentation accuracy (%)', '=accuracy(sum(consignment_documents)+sum(delivery_paperwork),sum(missing_documentation))', 'pct', 'k'],
                    ['outstanding_paperwork', 'Outstanding paperwork at the end of the week', '=last(outstanding_admin)', 'int', ''],
                    ['admin_workload', 'Freight administration workload', 'text', 'r'],
                    ['booking_amendments', 'Booking amendments', 'int', 'r'],
                    ['customer_communication', 'Customer communication', 'text', ''],
                    ['administrative_errors', 'Administrative errors (booking errors)', '=sum(booking_errors)', 'int', 'k'],
                ],
                'monthly' => [
                    ['bookings_month', 'Monthly freight bookings', '=sum(bookings_processed)', 'int', 'k'],
                    ['amendments_month', 'Booking amendments', '=wsum(booking_amendments)', 'int', ''],
                    ['documentation_accuracy_month', 'Documentation accuracy (%)', '=accuracy(sum(consignment_documents)+sum(delivery_paperwork),sum(missing_documentation))', 'pct', 'k'],
                    ['productivity', 'Administrative productivity', 'text', 'r'],
                    ['booking_errors_month', 'Booking errors', '=sum(booking_errors)', 'int', 'k'],
                    ['error_trends', 'Booking error trends', 'text', ''],
                    ['outstanding_paperwork_month', 'Outstanding paperwork at month end', '=last(outstanding_admin)', 'int', ''],
                    ['process_improvements', 'Process improvements', 'text', ''],
                    ['recommendations', 'Recommendations', 'text', 'r'],
                ],
            ],
            'distribution_service_manager' => [
                'daily' => [
                    ['jobs_received', 'Distribution jobs received', 'int', 'kr'],
                    ['routes_allocated', 'Distribution routes allocated', 'int', 'r'],
                    ['vehicles_assigned', 'Vehicles assigned', 'int', 'r'],
                    ['drivers_assigned', 'Drivers assigned', 'int', 'r'],
                    ['collections_completed', 'Collections completed', 'int', 'kr'],
                    ['deliveries_completed', 'Deliveries completed', 'int', 'kr'],
                    ['distribution_delays', 'Distribution delays', 'int', 'kr'],
                    ['failed_jobs', 'Failed distribution jobs', 'int', 'kr'],
                    ['operational_issues', 'Operational issues', 'text', ''],
                ],
                'weekly' => [
                    ['distribution_performance', 'Distribution performance', 'text', 'r'],
                    ['total_completed', 'Total jobs completed', '=sum(collections_completed)+sum(deliveries_completed)', 'int', 'k'],
                    ['route_efficiency', 'Route efficiency (jobs completed per route)', '=ratio(sum(collections_completed)+sum(deliveries_completed),sum(routes_allocated))', 'dec', 'k'],
                    ['vehicle_utilisation', 'Vehicle utilisation (%)', 'pct', 'kr'],
                    ['driver_productivity', 'Driver productivity (jobs per driver-day)', '=ratio(sum(collections_completed)+sum(deliveries_completed),sum(drivers_assigned))', 'dec', 'k'],
                    ['delays_week', 'Distribution delays', '=sum(distribution_delays)', 'int', ''],
                    ['failed_week', 'Failed deliveries', '=sum(failed_jobs)', 'int', 'k'],
                    ['operational_improvements', 'Operational improvements', 'text', ''],
                ],
                'monthly' => [
                    ['overall_performance', 'Overall distribution performance', 'text', 'r'],
                    ['distribution_efficiency', 'Distribution efficiency (% of jobs completed, not failed)', '=success(sum(collections_completed)+sum(deliveries_completed),sum(failed_jobs))', 'pct', 'k'],
                    ['route_trends', 'Route performance trends', 'text', ''],
                    ['operational_costs', 'Operational costs (£)', 'money', 'kr'],
                    ['vehicle_utilisation_month', 'Vehicle utilisation (average %, from weekly reports)', '=wavg(vehicle_utilisation)', 'pct', 'k'],
                    ['service_quality', 'Service quality', 'text', ''],
                    ['improvement_plans', 'Distribution improvement plans', 'text', 'r'],
                ],
            ],
            'route_planning_manager' => [
                'daily' => [
                    ['routes_planned', 'Routes planned', 'int', 'kr'],
                    ['jobs_allocated', 'Jobs allocated', 'int', 'kr'],
                    ['drivers_assigned', 'Drivers assigned', 'int', 'r'],
                    ['vehicles_allocated', 'Vehicles allocated', 'int', 'r'],
                    ['route_changes', 'Route changes', 'int', 'kr'],
                    ['missed_delayed', 'Missed or delayed jobs', 'int', 'kr'],
                    ['planning_issues', 'Planning issues', 'text', ''],
                    ['actions', 'Actions taken', 'text', ''],
                ],
                'weekly' => [
                    ['total_routes', 'Total routes planned', '=sum(routes_planned)', 'int', 'k'],
                    ['planning_accuracy', 'Planning accuracy (% of routes run without changes)', '=accuracy(sum(routes_planned),sum(route_changes))', 'pct', 'k'],
                    ['route_efficiency', 'Route efficiency (jobs per route)', '=ratio(sum(jobs_allocated),sum(routes_planned))', 'dec', 'k'],
                    ['allocation_efficiency', 'Vehicle and driver allocation efficiency', 'text', ''],
                    ['unplanned_changes', 'Unplanned route changes', '=sum(route_changes)', 'int', ''],
                    ['delayed_week', 'Delayed deliveries (missed or delayed jobs)', '=sum(missed_delayed)', 'int', 'k'],
                    ['fuel_efficiency', 'Fuel efficiency (miles per gallon)', 'dec', 'kr'],
                    ['planned_mileage', 'Planned mileage (miles)', 'int', 'r'],
                    ['planning_improvements', 'Planning improvements', 'text', ''],
                ],
                'monthly' => [
                    ['planning_performance', 'Route planning performance', 'text', 'r'],
                    ['efficiency_trends', 'Route efficiency trends', 'text', ''],
                    ['mileage_month', 'Mileage (planned miles, from weekly reports)', '=wsum(planned_mileage)', 'int', 'k'],
                    ['mileage_analysis', 'Mileage analysis', 'text', ''],
                    ['vehicle_utilisation', 'Vehicle utilisation', 'text', ''],
                    ['planning_accuracy_month', 'Planning accuracy (%)', '=accuracy(sum(routes_planned),sum(route_changes))', 'pct', 'k'],
                    ['cost_savings', 'Operational cost savings (£)', 'money', 'kr'],
                    ['recommendations', 'Route optimisation recommendations', 'text', 'r'],
                ],
            ],
            'business_development_manager' => [
                'daily' => [
                    ['enquiries', 'New business enquiries', 'int', 'kr'],
                    ['customers_contacted', 'Potential customers contacted', 'int', 'kr'],
                    ['calls_meetings', 'Sales calls and meetings', 'int', 'kr'],
                    ['quotations', 'Quotations prepared', 'int', 'kr'],
                    ['quotation_value', 'Value of quotations prepared (£)', 'money', 'r'],
                    ['new_opportunities', 'New opportunities', 'text', ''],
                    ['follow_ups', 'Customer follow-ups', 'int', 'r'],
                    ['bd_activities', 'Business development activities', 'text', ''],
                ],
                'weekly' => [
                    ['leads_generated', 'New leads generated', '=sum(enquiries)', 'int', 'k'],
                    ['customer_meetings', 'Customer meetings and calls', '=sum(calls_meetings)', 'int', ''],
                    ['quotations_submitted', 'Quotations submitted', '=sum(quotations)', 'int', 'k'],
                    ['opportunities', 'New business opportunities', 'text', ''],
                    ['pipeline_value', 'Sales pipeline value (£)', 'money', 'kr'],
                    ['deals_secured', 'Deals secured', 'int', 'kr'],
                    ['deals_value', 'Value of deals secured (£)', 'money', 'kr'],
                    ['lost_opportunities', 'Lost opportunities', 'int', 'r'],
                    ['lost_reasons', 'Lost opportunities: reasons', 'text', ''],
                    ['follow_up_actions', 'Follow-up actions', 'text', ''],
                ],
                'monthly' => [
                    ['new_customers', 'New customers secured', '=wsum(deals_secured)', 'int', 'k'],
                    ['new_business_revenue', 'Revenue generated from new business (£)', 'money', 'kr'],
                    ['pipeline_month', 'Sales pipeline value at month end (£)', '=wlast(pipeline_value)', 'money', 'k'],
                    ['conversion_rate', 'Conversion rate (% of quotations that became deals)', '=pct(wsum(deals_secured),sum(quotations))', 'pct', 'k'],
                    ['customer_acquisition', 'Customer acquisition', 'text', ''],
                    ['growth_opportunities', 'Business growth opportunities', 'text', ''],
                    ['competitor_observations', 'Competitor observations', 'text', ''],
                    ['next_month_target', "Next month's sales target (£)", 'money', 'r'],
                    ['next_month_targets', "Next month's sales targets: details", 'text', 'r'],
                ],
            ],
            'operations_supervisor' => [
                'daily' => [
                    ['staff_rostered', 'Staff rostered', 'int', 'r'],
                    ['staff_present', 'Staff attendance: staff present', 'int', 'kr'],
                    ['work_allocated', 'Daily work allocation (tasks allocated)', 'int', 'r'],
                    ['team_tasks_completed', 'Tasks completed by the team', 'int', 'kr'],
                    ['team_productivity', 'Team productivity', 'text', ''],
                    ['operational_problems', 'Operational problems', 'text', ''],
                    ['performance_issues', 'Employee performance issues', 'text', ''],
                    ['health_safety_concerns', 'Health and safety concerns (number)', 'int', 'kr'],
                    ['health_safety_details', 'Health and safety concerns: details', 'text', ''],
                    ['actions', 'Actions taken', 'text', ''],
                ],
                'weekly' => [
                    ['team_performance', 'Team performance', 'text', 'r'],
                    ['attendance_rate', 'Staff attendance (% of rostered staff present)', '=pct(sum(staff_present),sum(staff_rostered))', 'pct', 'k'],
                    ['late_arrivals', 'Punctuality: late arrivals', 'int', 'r'],
                    ['work_completion', 'Work completion (% of allocated tasks completed)', '=pct(sum(team_tasks_completed),sum(work_allocated))', 'pct', 'k'],
                    ['productivity_issues', 'Productivity issues', 'text', ''],
                    ['operational_incidents', 'Operational incidents (health and safety)', '=sum(health_safety_concerns)', 'int', 'k'],
                    ['outstanding_team_tasks', 'Outstanding tasks', 'text', ''],
                    ['staff_support', 'Staff support requirements', 'text', ''],
                ],
                'monthly' => [
                    ['team_performance_month', 'Overall team performance', 'text', 'r'],
                    ['productivity_trends', 'Employee productivity trends', 'text', ''],
                    ['attendance_month', 'Attendance (% of rostered staff present)', '=pct(sum(staff_present),sum(staff_rostered))', 'pct', 'k'],
                    ['late_month', 'Late arrivals', '=wsum(late_arrivals)', 'int', ''],
                    ['operational_efficiency', 'Operational efficiency (% of allocated tasks completed)', '=pct(sum(team_tasks_completed),sum(work_allocated))', 'pct', 'k'],
                    ['training_requirements', 'Staff training requirements', 'text', ''],
                    ['recurring_problems', 'Recurring problems', 'text', ''],
                    ['recommendations', 'Improvement recommendations', 'text', 'r'],
                ],
            ],
            'financial_accounts_manager' => [
                'daily' => [
                    ['payments_received', 'Customer payments received (£)', 'money', 'kr'],
                    ['supplier_invoices', 'Supplier invoices received (number)', 'int', 'r'],
                    ['supplier_invoices_value', 'Supplier invoices received (£)', 'money', 'r'],
                    ['payments_processed', 'Payments processed (£)', 'money', 'kr'],
                    ['invoices_raised', 'Customer invoices raised (number)', 'int', 'kr'],
                    ['invoices_raised_value', 'Customer invoices raised (£)', 'money', 'kr'],
                    ['payment_queries', 'Outstanding payment queries', 'int', 'r'],
                    ['bank_issues', 'Bank and transaction issues', 'text', ''],
                    ['urgent_matters', 'Urgent financial matters', 'text', ''],
                ],
                'weekly' => [
                    ['weekly_income', 'Weekly income (£)', '=sum(payments_received)', 'money', 'k'],
                    ['weekly_expenditure', 'Weekly expenditure (£)', '=sum(payments_processed)', 'money', 'k'],
                    ['outstanding_invoices', 'Outstanding customer invoices (£)', 'money', 'kr'],
                    ['supplier_commitments', 'Supplier payment commitments (£)', 'money', 'r'],
                    ['debtors_over_90', 'Aged debtors over 90 days (£)', 'money', 'kr'],
                    ['aged_debtors', 'Aged debtors overview', 'text', ''],
                    ['cash_position', 'Cash flow position: closing cash balance (£)', 'money', 'kr'],
                    ['discrepancies', 'Financial discrepancies', 'text', ''],
                    ['actions_required', 'Actions required', 'text', ''],
                ],
                'monthly' => [
                    ['monthly_revenue', 'Monthly revenue: customer invoices raised (£)', '=sum(invoices_raised_value)', 'money', 'k'],
                    ['monthly_receipts', 'Customer payments received (£)', '=sum(payments_received)', 'money', ''],
                    ['monthly_expenditure', 'Monthly expenditure (£)', '=sum(payments_processed)', 'money', 'k'],
                    ['net_cash_movement', 'Profit and loss summary: receipts less expenditure (£)', '=sum(payments_received)-sum(payments_processed)', 'money', 'k'],
                    ['pl_summary', 'Profit and loss summary', 'text', 'r'],
                    ['debtor_creditor', 'Debtor and creditor position', 'text', ''],
                    ['outstanding_invoices_month', 'Outstanding invoices at month end (£)', '=wlast(outstanding_invoices)', 'money', 'k'],
                    ['cash_flow_analysis', 'Cash flow analysis', 'text', ''],
                    ['budget_performance', 'Budget performance', 'text', ''],
                    ['financial_risks', 'Financial risks', 'text', ''],
                    ['recommendations', 'Recommendations', 'text', 'r'],
                ],
            ],
        ];
    }

    /** Normalises a field definition to an associative array. */
    public static function field(array $f): array
    {
        if (str_starts_with($f[2], '=')) {
            return ['key' => $f[0], 'label' => $f[1], 'type' => 'calc', 'formula' => substr($f[2], 1), 'display' => $f[3],
                'kpi' => str_contains($f[4] ?? '', 'k'), 'required' => false];
        }
        return ['key' => $f[0], 'label' => $f[1], 'type' => $f[2], 'formula' => null, 'display' => $f[2],
            'kpi' => str_contains($f[3] ?? '', 'k'), 'required' => str_contains($f[3] ?? '', 'r')];
    }
}
