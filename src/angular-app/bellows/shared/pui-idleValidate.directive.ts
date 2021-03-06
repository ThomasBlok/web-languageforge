import * as angular from 'angular';

export const PuiIdleValidate = ($interval: angular.IIntervalService) => (<angular.IDirective> {
  restrict: 'A',
  scope: {
    idleValidate: '&',
    idleValidateKeypress: '&',
    idleValidateMsec: '='
  },
  link($scope: any, $element) {
    let intervalTimer: angular.IPromise<any>;
    let milliseconds = $scope.idleValidateMsec;
    if (!angular.isNumber($scope.idleValidateMsec)) {
      milliseconds = 1000;
    }

    $element.bind('keyup', function () {
      if (angular.isFunction($scope.idleValidateKeypress)) {
        $scope.$apply($scope.idleValidateKeypress);
      }

      if (angular.isDefined(intervalTimer)) {
        $interval.cancel(intervalTimer);
      }

      intervalTimer = $interval(() => $scope.idleValidate(), milliseconds, 1);
    });
  }
});

PuiIdleValidate.$inject = ['$interval'];
