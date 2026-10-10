---
paths:
  - app/Providers/FortifyServiceProvider.php
---

# Providers

## Keep Fortify authentication centralized
Register Fortify action classes in `configureActions()` and keep email/username lookup and password verification in `configureAuthentication()`. Preserve the authentication Timebox and configured password rehash-on-login behavior.
