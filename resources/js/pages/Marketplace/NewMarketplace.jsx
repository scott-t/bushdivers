import {
  Box,
  Button,
  Card,
  CardBody,
  Flex,
  Grid,
  GridItem,
  Heading,
  Image,
  Input,
  Modal,
  ModalBody,
  ModalCloseButton,
  ModalContent,
  ModalHeader,
  ModalOverlay,
  Select,
  SimpleGrid,
  Text,
  useDisclosure,
} from '@chakra-ui/react'
import { Link } from '@inertiajs/react'
import React, { useMemo, useState } from 'react'

import AppLayout from '../../components/layout/AppLayout'
import { displayNumber } from '../../helpers/number.helpers.js'

const AircraftDetailModal = ({ isOpen, onClose, aircraft, buyer }) => {
  if (!aircraft) return null

  return (
    <Modal isOpen={isOpen} onClose={onClose} size="2xl">
      <ModalOverlay />
      <ModalContent>
        <ModalHeader>{aircraft.name}</ModalHeader>
        <ModalCloseButton />
        <ModalBody pb={6}>
          {aircraft.rental_image && (
            <Box w="100%" mb={4}>
              <Image
                w="100%"
                borderRadius="md"
                src={aircraft.rental_image}
                alt={aircraft.name}
              />
            </Box>
          )}
          <Box mb={4}>
            <Text fontSize="lg" fontWeight="bold">
              Price Range:
            </Text>
            {aircraft.can_purchase_new && (
              <Text>New: ${displayNumber(aircraft.new_price)}</Text>
            )}
            <Text>
              Used: ${displayNumber(aircraft.used_low_price)} - $
              {displayNumber(aircraft.used_high_price)}
            </Text>
          </Box>
          <SimpleGrid columns={2} spacing={2} mb={4}>
            <Box>
              <Text fontWeight="bold">Manufacturer:</Text>
              <Text>{aircraft.manufacturer?.name || 'Unknown'}</Text>
            </Box>
            <Box>
              <Text fontWeight="bold">Type:</Text>
              <Text>{aircraft.type}</Text>
            </Box>
            <Box>
              <Text fontWeight="bold">Powerplants:</Text>
              <Text>
                {aircraft.number_of_engines} x {aircraft.powerplants}
              </Text>
            </Box>
            <Box>
              <Text fontWeight="bold">Fuel Type:</Text>
              <Text>{aircraft.fuel_type === 1 ? 'Avgas' : 'Jet Fuel'}</Text>
            </Box>
            <Box>
              <Text fontWeight="bold">Fuel Capacity:</Text>
              <Text>{displayNumber(aircraft.fuel_capacity)} gal</Text>
            </Box>
            <Box>
              <Text fontWeight="bold">ZFW:</Text>
              <Text>{displayNumber(aircraft.zfw)} lbs</Text>
            </Box>
            <Box>
              <Text fontWeight="bold">MTOW:</Text>
              <Text>{displayNumber(aircraft.mtow)} lbs</Text>
            </Box>
            <Box>
              <Text fontWeight="bold">Cargo Capacity:</Text>
              <Text>{displayNumber(aircraft.cargo_capacity)} lbs</Text>
            </Box>
            <Box>
              <Text fontWeight="bold">PAX Capacity:</Text>
              <Text>{aircraft.pax_capacity}</Text>
            </Box>
            <Box>
              <Text fontWeight="bold">Service Ceiling:</Text>
              <Text>{displayNumber(aircraft.service_ceiling)} ft</Text>
            </Box>
            <Box>
              <Text fontWeight="bold">Range:</Text>
              <Text>{displayNumber(aircraft.range)} nm</Text>
            </Box>
            <Box>
              <Text fontWeight="bold">Cruise Speed:</Text>
              <Text>{aircraft.cruise_speed} KIAS</Text>
            </Box>
          </SimpleGrid>
          <Flex gap={2} justifyContent="flex-end">
            {aircraft.can_purchase_new && (
              <Link href={`/marketplace/purchase/new/${aircraft.id}/${buyer}`}>
                <Button colorScheme="green">Purchase New</Button>
              </Link>
            )}
            <Link href={`/marketplace/list/used/${aircraft.id}/${buyer}`}>
              <Button colorScheme="blue">Purchase Used</Button>
            </Link>
          </Flex>
        </ModalBody>
      </ModalContent>
    </Modal>
  )
}

const AircraftCard = ({ aircraft, onClick }) => {
  return (
    <Card
      cursor="pointer"
      onClick={onClick}
      _hover={{ shadow: 'lg', transform: 'translateY(-2px)' }}
      transition="all 0.2s"
    >
      <CardBody>
        {aircraft.rental_image && (
          <Box w="100%" mb={3}>
            <Image
              w="100%"
              h="200px"
              objectFit="cover"
              borderRadius="md"
              src={aircraft.rental_image}
              alt={aircraft.name}
            />
          </Box>
        )}
        <Box>
          <Heading size="md" mb={2}>
            {aircraft.name}
          </Heading>
          <Text fontSize="sm" color="gray.600" mb={2}>
            {aircraft.manufacturer?.name || 'Unknown Manufacturer'}
          </Text>
          <Text fontSize="sm" mb={2}>
            {aircraft.type}
          </Text>
          <Box>
            {aircraft.can_purchase_new && (
              <Text fontSize="sm">
                New: ${displayNumber(aircraft.new_price)}
              </Text>
            )}
            <Text fontSize="sm">
              Used: ${displayNumber(aircraft.used_low_price)} - $
              {displayNumber(aircraft.used_high_price)}
            </Text>
          </Box>
        </Box>
      </CardBody>
    </Card>
  )
}

const NewMarketplace = ({
  fleet,
  manufacturers,
  sizes,
  aircraftTypes,
  buyer,
}) => {
  const [searchTerm, setSearchTerm] = useState('')
  const [selectedManufacturer, setSelectedManufacturer] = useState('')
  const [selectedFuelType, setSelectedFuelType] = useState('')
  const [selectedSize, setSelectedSize] = useState('')
  const [selectedAircraftType, setSelectedAircraftType] = useState('')
  const [selectedAircraft, setSelectedAircraft] = useState(null)
  const { isOpen, onOpen, onClose } = useDisclosure()

  const filteredFleet = useMemo(() => {
    return fleet.filter((aircraft) => {
      const matchesSearch =
        searchTerm === '' ||
        aircraft.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
        aircraft.type.toLowerCase().includes(searchTerm.toLowerCase())

      const matchesManufacturer =
        selectedManufacturer === '' ||
        aircraft.manufacturer_id === parseInt(selectedManufacturer)

      const matchesFuelType =
        selectedFuelType === '' ||
        aircraft.fuel_type === parseInt(selectedFuelType)

      const matchesSize = selectedSize === '' || aircraft.size === selectedSize

      const matchesAircraftType =
        selectedAircraftType === '' ||
        aircraft.aircraft_type === parseInt(selectedAircraftType)

      return (
        matchesSearch &&
        matchesManufacturer &&
        matchesFuelType &&
        matchesSize &&
        matchesAircraftType
      )
    })
  }, [
    fleet,
    searchTerm,
    selectedManufacturer,
    selectedFuelType,
    selectedSize,
    selectedAircraftType,
  ])

  const handleCardClick = (aircraft) => {
    setSelectedAircraft(aircraft)
    onOpen()
  }

  return (
    <Box>
      <Grid templateColumns="250px 1fr" gap={6}>
        <GridItem>
          <Card position="sticky" top="20px">
            <CardBody>
              <Heading size="md" mb={4}>
                Filters
              </Heading>
              <Box mb={4}>
                <Text mb={2} fontWeight="bold">
                  Search
                </Text>
                <Input
                  placeholder="Search aircraft..."
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                />
              </Box>
              <Box mb={4}>
                <Text mb={2} fontWeight="bold">
                  Manufacturer
                </Text>
                <Select
                  value={selectedManufacturer}
                  onChange={(e) => setSelectedManufacturer(e.target.value)}
                >
                  <option value="">All</option>
                  {manufacturers.map((m) => (
                    <option key={m.id} value={m.id}>
                      {m.name}
                    </option>
                  ))}
                </Select>
              </Box>
              <Box mb={4}>
                <Text mb={2} fontWeight="bold">
                  Type
                </Text>
                <Select
                  value={selectedAircraftType}
                  onChange={(e) => setSelectedAircraftType(e.target.value)}
                >
                  <option value="">All</option>
                  {Object.entries(aircraftTypes).map(([key, label]) => (
                    <option key={key} value={key}>
                      {label}
                    </option>
                  ))}
                </Select>
              </Box>
              <Box mb={4}>
                <Text mb={2} fontWeight="bold">
                  Fuel Type
                </Text>
                <Select
                  value={selectedFuelType}
                  onChange={(e) => setSelectedFuelType(e.target.value)}
                >
                  <option value="">All</option>
                  <option value="1">Avgas</option>
                  <option value="2">Jet Fuel</option>
                </Select>
              </Box>
              <Box mb={4}>
                <Text mb={2} fontWeight="bold">
                  Size
                </Text>
                <Select
                  value={selectedSize}
                  onChange={(e) => setSelectedSize(e.target.value)}
                >
                  <option value="">All</option>
                  {sizes.map((size) => (
                    <option key={size} value={size}>
                      {size === 'S'
                        ? 'Small'
                        : size === 'M'
                          ? 'Medium'
                          : 'Large'}
                    </option>
                  ))}
                </Select>
              </Box>
              <Button
                size="sm"
                width="100%"
                onClick={() => {
                  setSearchTerm('')
                  setSelectedManufacturer('')
                  setSelectedFuelType('')
                  setSelectedSize('')
                  setSelectedAircraftType('')
                }}
              >
                Clear Filters
              </Button>
            </CardBody>
          </Card>
        </GridItem>
        <GridItem>
          <Flex justifyContent="space-between" mb={4}>
            <Heading size="lg">Aircraft Marketplace</Heading>
            <Text color="gray.600">
              {filteredFleet.length} aircraft available
            </Text>
          </Flex>
          {filteredFleet.length === 0 ? (
            <Card>
              <CardBody>
                <Text textAlign="center">
                  No aircraft found matching your filters
                </Text>
              </CardBody>
            </Card>
          ) : (
            <SimpleGrid columns={3} gap={5}>
              {filteredFleet.map((aircraft) => (
                <AircraftCard
                  key={aircraft.id}
                  aircraft={aircraft}
                  onClick={() => handleCardClick(aircraft)}
                />
              ))}
            </SimpleGrid>
          )}
        </GridItem>
      </Grid>
      <AircraftDetailModal
        isOpen={isOpen}
        onClose={onClose}
        aircraft={selectedAircraft}
        buyer={buyer}
      />
    </Box>
  )
}

NewMarketplace.layout = (page) => (
  <AppLayout
    children={page}
    title="Marketplace"
    heading="Aircraft Marketplace"
  />
)

export default NewMarketplace
