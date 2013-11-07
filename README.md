A Facebook importer for use with the Keyring and Keyring Social Importers WordPress plugins.

Drop this in `keyring-social-importers/importers/` and make this change to `keyring/includes/services/extended/facebook.php`:

On (or about) line 20, where it says:

    $this->set_endpoint( 'authorize', 'https://www.facebook.com/dialog/oauth', 'GET' );

change it to:

    $this->set_endpoint( 'authorize', 'https://www.facebook.com/dialog/oauth?scope=read_stream&', 'GET' );

You may have to delete and re-add your Facebook connection.

