angular.module('sharedService', []).factory('SharedService', function() {

    var SharedService;

    SharedService = (function() {

        function SharedService() {
            /* method code... */
        }

        SharedService.prototype.setData = function(name, data) {
            /* method code... */
            window[name] = data;
        };
        return SharedService;

    })();

    if (typeof(window.angularSharedService) === 'undefined' || window.angularSharedService === null) {
        window.angularSharedService = new SharedService();
    }
    return window.angularSharedService;
});

var programWorkshop = angular.module("appModule", [ 'ngAnimate', 'sharedService', 'nsPopover' ]);

programWorkshop.config(function ($interpolateProvider) {
    $interpolateProvider.startSymbol('[[').endSymbol(']]');
});

programWorkshop.run(function($rootScope){

    $rootScope.workshopData = {};
});

programWorkshop.controller('appCtrl', function ($scope, SharedService) {

    // popover for work groups
    $scope.shouldDisplayPopover = function() {
        return $scope.displayPopover;
    };

    // get work group list into popover
    $scope.getData = function(workGroupId, workshopId, event){

        var action = (event.target.checked ? '1' : '0');
        var urlAction = $('#checkBox-'+workGroupId).attr('data-src');
        var url = urlAction.replace('id', workshopId);
        $.ajax({
            type: "POST",
            url: url,
            data: {workGroupId:workGroupId, isChecked: action},
            success: function(response) {
                window.wg_data = response['workGroups'];
                window.ws_id = response['id'];
                //assign updated value to parent page
                var event = new Event('wg');
                window.dispatchEvent(event);
            }
        });
    };

    $scope.actionsClick = function($event,id,action,index){
        if($event){

            $event.stopPropagation();
            $event.preventDefault();
        }
        var urlAction;

        if(action == 'deleteWorkshopInProgram'){

            urlAction = $('#remove-workshop-'+id).attr('data-src');
            var urlRemoveWorkshop = urlAction.replace('workshopId', id);
            loadDeleteDocumentModal(urlRemoveWorkshop, "workshop_remove-modal");
        }
    };

    var init = function () {
        $scope.workshopData = templateData.workshopData;
        $scope.currentUserId = templateData.currentUserId;
    };
    init();

    $scope.workGroup = [];
    angular.forEach($scope.workshopData, function(value, key) {
        $scope.workGroup[$scope.workshopData[key].id] = $scope.workshopData[key].workGroups;
    });

    $scope.allWorkGroup = [];
    angular.forEach($scope.workshopData, function(value, key) {
        $scope.allWorkGroup[$scope.workshopData[key].id] = $scope.workshopData[key].allWorkGroups;
    });

    // Listen for the event.
    window.addEventListener('wg', function (e) {
        $scope.workGroup[window.ws_id] = window.wg_data;
        $scope.$apply();
    }, false);

});

$(function() {

    // filter
    var urlAction = $('#programFilter').attr('data-src');
    $( "#myDataFilter" ).on( "click", function() {
        $( "#myDataWS" ).trigger( "click" );
        $( "#myDataWG" ).trigger( "click" );
        $( "#myGanttDataWS" ).trigger( "click" );
        $('#myDataFilter').addClass('btn-grey');
        $('#allDataFilter').removeClass('btn-grey');
        programSession(urlAction, $(this).data('var'));
    });
    $( "#allDataFilter" ).on( "click", function() {

        $( "#allDataWS" ).trigger( "click" );
        $( "#allDataWG" ).trigger( "click" );
        $( "#allGanttDataWS" ).trigger( "click" );
        $('#allDataFilter').addClass('btn-grey');
        $('#myDataFilter').removeClass('btn-grey');
        programSession(urlAction, $(this).data('var'));
    });

    var filterValue = $('#programFilter').val();
    if(filterValue == 1){
        $("#myDataFilter").trigger( "click" );
        $("#myDataFilter").addClass('btn-grey');
    }else{
        $("#allDataFilter").trigger( "click" );
        $("#allDataFilter").addClass('btn-grey');
    }

    $( ".workshopLink" ).on( "click", function() {
        window.location = $(this).attr('href');
    });
});