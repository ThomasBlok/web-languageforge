import * as angular from 'angular';
import 'ng-intl-tel-input';

import { CoreModule } from '../../core/core.module';
import { NoticeModule } from '../../core/notice/notice.module';
import { UserProfileAppComponent } from './user-profile-app.component';

export const UserProfileAppModule = angular
  .module('userprofile', ['ui.bootstrap', 'pascalprecht.traslate', 'ngIntlTelInput',
    CoreModule, NoticeModule
  ])
  .component('userProfileApp', UserProfileAppComponent)
  .config(['$translateProvider', ($translateProvider: angular.translate.ITranslateProvider) => {
    // configure interface language filepath
    $translateProvider.useStaticFilesLoader({
      prefix: '/angular-app/bellows/lang/',
      suffix: '.json'
    });
    $translateProvider.preferredLanguage('en');
    $translateProvider.useSanitizeValueStrategy('escape');
  }])
  .name;
