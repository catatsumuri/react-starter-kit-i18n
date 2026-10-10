---
paths:
    - 'routes/**'
---

# Routes

## Extend the existing notification system

Extend app notifications through `SendGeneralNotification` and `GeneralNotification`, using Laravel database notifications and the existing Inertia shared-prop, `NotificationButton`, and `notifications.read` flow. Do not introduce a parallel notification model, table, endpoint, or delivery pipeline.
