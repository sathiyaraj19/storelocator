(function ($, Drupal, drupalSettings) {
  Drupal.behaviors.storeLocator = {
    attach: function (context, settings) {
      var map = null; // Initialize map variable

      // Ensure the DOM element exists
      var $mapElement = $('#map', context);
      if (!$mapElement.length) {
        console.error('Map element #map not found.');
        return;
      }

      // Check if the map instance already exists and remove if it does
      if (typeof Drupal.behaviors.storeLocator.map !== 'undefined') {
        Drupal.behaviors.storeLocator.map.remove();
      }

      // Initialize a new map instance and save it
      map = L.map('map');
      Drupal.behaviors.storeLocator.map = map;

      // Add OpenStreetMap tiles to the map
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
      }).addTo(map);

      // Center the map on user's location by default using geolocation
      if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(function (position) {
          var userLat = position.coords.latitude;
          var userLng = position.coords.longitude;
          getNearestStores(userLat, userLng);
        }, function (error) {
          console.error('Error getting user location:', error);
          // Fallback to default coordinates if geolocation fails
          map.setView([13.0843, 80.2705], 13); // Default to Chennai coordinates
        });
      } else {
        console.error('Geolocation is not supported by this browser.');
        // Fallback to default coordinates if geolocation is not supported
        map.setView([13.0843, 80.2705], 13); // Default to Chennai coordinates
      }

      // Function to remove all markers from the map
      function removeAllMarkers() {
        if (map) {
          map.eachLayer(function (layer) {
            if (layer instanceof L.Marker) {
              map.removeLayer(layer);
            }
          });
        }
      }

      // Function to fetch nearest stores based on provided coordinates
      function getNearestStores(lat, lng) {
        map.setView([lat, lng], 13);
        $.ajax({
          url: '/store-locator/stores/' + lat + '/' + lng,
          type: 'GET',
          dataType: 'json',
          success: function (data) {
            removeAllMarkers(); // Remove existing markers before adding new ones
            $('#location-details ul').remove(); // Clear existing store list items

            // Create a new <ul> element for the store list
            var $storeList = $('<ul></ul>');

            // Add markers for each store returned by the AJAX call
            data.forEach(function (store) {
              var marker = L.marker([store.lat, store.lon]).addTo(map);
              
              // Create popup content with a clickable title and address
              var popupContent = '<h3><a href="/node/' + store.id + '">' + store.title + '</a></h3>';
              popupContent += '<p>' + store.address + '</p>'; // Assuming store.address is available
              
              marker.bindPopup(popupContent);

              // Create a list item for the store and append it to the new <ul>
              var listItem = '<li><a href="/node/' + store.id + '">' + store.title + '</a><br>' + store.address + '</li>';
              $storeList.append(listItem);
            });

            // Append the new <ul> to the #location-details div
            $('#location-details').append($storeList);
            
            // Set focus back to the map after updating the stores
            map.getContainer().focus();
          },
          error: function (jqXHR, textStatus, errorThrown) {
            console.error('AJAX error:', textStatus, errorThrown);
          }
        });
      }

      // Handle form submission to geocode address and fetch nearest stores
      $('#store-address-submit', context).click(function (e) {
        e.preventDefault();
        var address = $('#store-address', context).val();
        geocodeAddress(address);
      });

      // Function to geocode address using ArcGIS API
      function geocodeAddress(address) {
        var apiUrl = 'https://geocode.arcgis.com/arcgis/rest/services/World/GeocodeServer/findAddressCandidates';
        var params = {
          f: 'json',
          singleLine: address
        };

        // Make AJAX request to geocode address
        $.ajax({
          url: apiUrl,
          data: params,
          dataType: 'json',
          success: function (data) {
            if (data.candidates && data.candidates.length > 0) {
              var location = data.candidates[0].location;
              getNearestStores(location.y, location.x);
            } else {
              console.error('No location found for the address');
            }
          },
          error: function (xhr, status, error) {
            console.error('Error fetching data:', error);
          }
        });
      }
    }
  };
})(jQuery, Drupal, drupalSettings);
