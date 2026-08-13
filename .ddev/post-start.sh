#!/bin/bash
set -e -o pipefail

# symlink ddev drupal modules to kickstart the testing
ln -rsfT .ddev/masstimes_widget/drupal/modules web/modules/ddev

./.ddev/commands/web/poser

# If db empty, do initial setup
if [ -z "$(drush status --field=bootstrap)" ]; then
  drush si --site-name=masstimes_widget --account-pass=1 -y
  # Core's Navigation module supersedes Toolbar. For now our local development
  # preference continues to be admin toolbar
  if drush pm:list --status=enabled --format=list | grep -qx navigation; then
    drush pm:uninstall -y navigation
  fi
  drush en -y devel admin_toolbar admin_toolbar_tools
  drush en -y masstimes_widget masstimes_widget_ddev

  drush rap authenticated 'access devel information'
fi

# Always output this
drush uli
