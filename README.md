# Google Drive Extractor

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](https://github.com/keboola/google-drive-extractor/blob/master/LICENSE)

This component extracts data from Google Drive files and spreadsheets.

## Example Configuration

```yaml
parameters:
  outputBucket: "in.c-google-drive-extractor-testcfg1"
  sheets:
    -
      id: 0
      fileId: FILE_ID
      fileTitle: FILE_TITLE
      sheetId: THE_GID_OF_THE_SHEET
      sheetTitle: SHEET_TITLE
      outputTable: FILE_TITLE
      enabled: true
```

## OAuth Registration

Note that this extractor uses the [Keboola OAuth Bundle](https://github.com/keboola/oauth-v2-bundle) to store OAuth credentials.

1. Create an application in the Google Developer console.

- Enable the following APIs: `Google Drive API`, `Google Sheets API`
- Go to the `Credentials` section and create new credentials of type `OAuth Client ID`. Use `https://SYRUP_INSTANCE.keboola.com/oauth-v2/authorize/keboola.ex-google-drive/callback` as the redirect URI.

2. Register the application in Keboola Oauth [http://docs.oauthv2.apiary.io/#reference/manage/addlist-supported-api/add-new-component](http://docs.oauthv2.apiary.io/#reference/manage/addlist-supported-api/add-new-component).


```json
{ 
    "component_id": "keboola.ex-google-drive",
    "friendly_name": "Google Drive Extractor",
    "app_key": "XXX.apps.googleusercontent.com",
    "app_secret": "",
    "auth_url": "https://accounts.google.com/o/oauth2/v2/auth?response_type=code&redirect_uri=%%redirect_uri%%&client_id=%%client_id%%&access_type=offline&prompt=consent&scope=https://www.googleapis.com/auth/drive https://www.googleapis.com/auth/userinfo.profile https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/spreadsheets.readonly",
    "token_url": "https://www.googleapis.com/oauth2/v4/token",
    "oauth_version": "2.0"
}
```

## Development

The application is developed locally using Test-Driven Development (TDD).

**Setup Instructions**
1. Clone the repository: `git clone git@github.com:keboola/google-drive-extractor.git`
2. Change to the project directory: `cd google-drive-extractor`
3. Build the Docker image: `docker-compose build`
4. Install dependencies: `docker-compose run --rm dev composer install --no-scripts`
5. Obtain working OAuth credentials:
    - Go to Google's [OAuth 2.0 Playground](https://developers.google.com/oauthplayground). 
    - In the configuration (click the cog wheel in the top-right side), check `Use your own OAuth credentials` and paste your OAuth Client ID and Secret.
    - Complete the authorization flow to generate `Access` and `Refresh` tokens. 
    - Create a `.env` file from the template [.env.template](./.env.template).
    - Fill in the `.env` with your OAuth Client ID, Secret, and obtained tokens.
6. Run the tests: `docker-compose run --rm dev composer ci`

## License

MIT licensed, see the [LICENSE](./LICENSE) file.
